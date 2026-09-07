<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use App\Models\Plan;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\CreditService;
use App\Services\Notificador;
use App\Services\WompiService;
use App\Services\NodeRenderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PaymentWebhookController extends Controller
{
    /**
     * Endpoint genérico para recibir webhooks de pasarelas.
     */
    public function __construct(
        private CreditService $creditService,
        private Notificador $notificador,
        private WompiService $wompi,
        private NodeRenderService $renderer,
    ) {}

    public function handle(Request $request, string $provider)
    {
        $raw = $request->getContent();
        $data = $request->all();

        if (strtolower($provider) === 'wompi') {
            $data = $this->normalizarWompi($data);
            if ($data === null) {
                return response()->json(['message' => 'Evento de Wompi inválido o firma incorrecta.'], 403);
            }
        }

        $secretKey = env('PAYMENT_WEBHOOK_SECRET_' . strtoupper($provider));
        $sigHeader = $request->header('X-Signature') ?? $request->header('X-Signature-256') ?? $request->header('Signature');
        if ($secretKey && $sigHeader) {
            $computed = hash_hmac('sha256', $raw, $secretKey);
            if (! hash_equals($computed, $sigHeader)) {
                Log::warning("Webhook signature mismatch for provider {$provider}");
                return response()->json(['message' => 'Firma inválida.'], 403);
            }
        }

        $status = $data['status'] ?? $data['estado'] ?? null;
        $email = $data['user_email'] ?? $data['email'] ?? ($data['metadata']['email'] ?? null);
        $planName = $data['plan'] ?? $data['metadata']['plan'] ?? null;

        $providerEventId = $data['id'] ?? $data['event_id'] ?? $data['data']['id'] ?? $data['data']['object'] ?? null;
        if (is_array($providerEventId)) {
            $providerEventId = $providerEventId['id'] ?? null;
        }

        if (! $status) {
            return response()->json(['message' => 'Payload inválido.'], 400);
        }

        $existing = null;
        if ($providerEventId) {
            $existing = PaymentTransaction::where('provider', $provider)->where('provider_event_id', $providerEventId)->first();
        }

        if ($existing && $existing->processed_at) {
            return response()->json(['message' => 'Evento ya procesado.'], 200);
        }

        $tx = $existing ?? PaymentTransaction::create([
            'provider' => $provider,
            'provider_event_id' => $providerEventId,
            'status' => $status,
            'payload' => $data,
            'amount' => null,
            'currency' => $data['currency'] ?? null,
        ]);

        if (in_array(strtoupper($status), ['SUCCESS', 'APPROVED', 'PAID'])) {
            $user = null;
            if ($email) {
                $user = User::where('email', $email)->first();
            }

            if (! $user && ($data['user_id'] ?? $data['metadata']['user_id'] ?? null)) {
                $uid = $data['user_id'] ?? $data['metadata']['user_id'];
                $user = User::find($uid);
            }

            if (! $user) {
                $tx->processed_at = now();
                $tx->save();
                return response()->json(['message' => 'Usuario no encontrado; requiere metadata con email o user_id.'], 404);
            }

            if (! $planName) {
                $planName = $data['plan_name'] ?? null;
            }

            if ($planName) {
                $plan = Plan::where('nombre', $planName)->first();
                if ($plan) {
                    $old = $user->plan?->nombre;
                    $user->update(['plan_id' => $plan->id]);
                    // Multiempresa: el plan efectivo vive en la empresa GOBERNANTE
                    // del grupo (si el usuario tiene negocios vinculados, un solo
                    // plan pagado cubre a todos).
                    $user->empresaDeCobro()?->empresaGobernante()?->update(['plan_id' => $plan->id]);
                    $user->renovarMembresia();
                    Auditoria::registrar(null, $user->id, 'PAGO', strtoupper($provider) . '_WEBHOOK', $old, $plan->nombre);
                    $tx->user_id = $user->id;
                    $tx->plan_id = $plan->id;

                    $this->notificador->aUsuario($user->id, 'PAGO',
                        'Pago de membresía confirmado',
                        "Tu plan {$plan->nombre} quedó activo hasta el {$user->membresia_vence_at?->format('d/m/Y')}. ¡Gracias por tu pago!");

                    $this->enviarReciboPago($user, "Suscripción mensual - Plan {$plan->nombre}",
                        (float) ($tx->amount ?? $plan->precio_mensual), $tx);
                }
            }

            $creditPackageId = $data['credit_package_id'] ?? $data['metadata']['credit_package_id'] ?? $data['package_id'] ?? null;
            if ($creditPackageId) {
                try {
                    $package = \App\Models\CreditPackage::find($creditPackageId);
                    if ($package) {
                        $this->creditService->creditUser($user->id, $package->module, (int) $package->credits, $tx->id, $package->id, 'Compra de créditos via webhook');
                        Auditoria::registrar(null, $user->id, 'PAGO', strtoupper($provider) . '_CREDIT', null, "+{$package->credits} {$package->module}");

                        $this->notificador->aUsuario($user->id, 'PAGO',
                            'Recarga de saldo confirmada',
                            "Se acreditaron {$package->credits} créditos de {$package->module} a tu billetera.");

                        $this->enviarReciboPago($user, "Recarga de saldo - {$package->name}",
                            (float) ($tx->amount ?? $package->price_cop), $tx);
                    }
                } catch (\Throwable $e) {
                    Log::error('Error al acreditar créditos: ' . $e->getMessage());
                }
            }

            $amount = $data['amount'] ?? $data['amount_in_cents'] ?? ($data['data']['amount'] ?? null);
            if ($amount !== null) {
                if (is_numeric($amount) && (int)$amount > 1000000) {
                    $tx->amount = ((float)$amount) / 100;
                } else {
                    $tx->amount = (float)$amount;
                }
            }

            $tx->status = $status;
            $tx->processed_at = now();
            $tx->payload = $data;
            $tx->save();

            return response()->json(['message' => 'Webhook procesado.'], 200);
        }

        $tx->status = $status;
        $tx->payload = $data;
        $tx->processed_at = now();
        $tx->save();
        return response()->json(['message' => 'Evento registrado (no exitoso).'], 200);
    }

    private function normalizarWompi(array $payload): ?array
    {
        if (! $this->wompi->verificarEvento($payload)) {
            return null;
        }

        $txWompi = $payload['data']['transaction'] ?? [];
        $referencia = (string) ($txWompi['reference'] ?? '');

        $normalizado = [
            'status' => $txWompi['status'] ?? null,
            'id' => $txWompi['id'] ?? null,
            'amount' => isset($txWompi['amount_in_cents']) ? ((int) $txWompi['amount_in_cents']) / 100 : null,
            'currency' => $txWompi['currency'] ?? 'COP',
            'user_email' => $txWompi['customer_email'] ?? null,
            'wompi_reference' => $referencia,
        ];

        if (preg_match('/^LOGIX-(PLAN|CRED)-(\d+)$/', $referencia, $m)) {
            $local = PaymentTransaction::find((int) $m[2]);
            if ($local) {
                $normalizado['user_id'] = $local->user_id ?? ($local->payload['user_id'] ?? null);
                if ($m[1] === 'PLAN') {
                    $normalizado['plan'] = $local->payload['plan'] ?? $local->plan?->nombre;
                } else {
                    $normalizado['credit_package_id'] = $local->payload['package_id'] ?? null;
                }
            }
        }

        return $normalizado;
    }

    /**
     * Envía el recibo con Logs estrictos para Render
     */
    private function enviarReciboPago(User $user, string $concepto, float $monto, PaymentTransaction $tx): void
    {
        try {
            $pdf = $this->renderer->pdf('recibo_pago', [
                'usuario' => $user,
                'concepto' => $concepto,
                'monto' => $monto,
                'transaccion' => $tx,
                'fecha' => now(),
            ]);

            $ruta = "recibos/recibo_{$tx->id}_" . now()->timestamp . '.pdf';
            Storage::disk('public')->put($ruta, $pdf);

            Log::info("PDF generado exitosamente en: " . Storage::disk('public')->path($ruta));

            $this->notificador->correo(
                $user->email,
                "Recibo de pago #{$tx->id} - Fénix",
                'Recibo de pago',
                [
                    "Hola {$user->name},",
                    "Confirmamos tu pago por concepto de: {$concepto}.",
                    'Monto: $' . number_format($monto, 0, ',', '.') . ' COP.',
                    'Adjuntamos el recibo en PDF. ¡Gracias por confiar en Fénix!',
                ],
                Storage::disk('public')->path($ruta),
                'PAGO',
            );
            
            Log::info("Método notificador->correo ejecutado para: {$user->email}");

        } catch (\Throwable $e) {
            Log::error('ERROR AL ENVIAR CORREO FACTURA: ' . $e->getMessage(), [
                'tx' => $tx->id,
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }
}