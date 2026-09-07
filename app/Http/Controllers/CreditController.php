<?php

namespace App\Http\Controllers;

use App\Models\CreditPackage;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Services\CreditService;
use App\Services\WompiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CreditController extends Controller
{
    public function __construct(private CreditService $credits, private WompiService $wompi) {}

    // List available packages (public for auth users)
    public function indexPackages(Request $request)
    {
        $module = $request->query('module');
        $q = CreditPackage::where('active', true);
        if ($module) $q->where('module', $module);
        return $q->orderBy('price_cop')->get();
    }

    // Saldo por módulo de la EMPRESA del usuario (compartido por todo su equipo)
    public function myCredits(Request $request)
    {
        $user = $request->user();
        if (! $user) return response()->json(['message' => 'No autorizado'], 401);

        return response()->json($this->credits->saldos($user));
    }

    /** Crea la sesión de pago (Wompi: PSE, Nequi, tarjeta) para recargar la billetera. */
    public function createSession(Request $request)
    {
        $data = $request->validate([
            'package_id' => ['required', 'exists:credit_packages,id'],
        ]);

        $package = CreditPackage::findOrFail($data['package_id']);
        $user = $request->user();

        // Transacción local para idempotencia y trazabilidad; la referencia viaja
        // a Wompi y regresa en el webhook para saber qué acreditar y a quién.
        $pt = PaymentTransaction::create([
            'provider' => 'wompi',
            'status' => 'PENDING',
            'user_id' => $user->id,
            'payload' => ['tipo' => 'recarga', 'package_id' => $package->id, 'user_id' => $user->id],
            'amount' => $package->price_cop,
            'currency' => 'COP',
        ]);

        $referencia = "LOGIX-CRED-{$pt->id}";
        $pt->update(['payload' => array_merge($pt->payload, ['reference' => $referencia])]);

        if ($error = $this->validarWompi()) {
            return $error;
        }

        return response()->json([
            'checkoutUrl' => $this->wompi->checkoutUrl((int) $package->price_cop, $referencia),
            'payment_transaction_id' => $pt->id,
            'reference' => $referencia,
        ]);
    }

    /** Verifica que la llave de Wompi corresponda a un comercio real antes de redirigir. */
    private function validarWompi()
    {
        if (! $this->wompi->configurado()) {
            return null; // en desarrollo devuelve la URL de prueba interna
        }
        $comercio = $this->wompi->verificarComercio();
        if (! $comercio['ok']) {
            return response()->json([
                'message' => 'La pasarela de pagos no está bien configurada: ' . ($comercio['error'] ?? 'llave inválida.'),
            ], 422);
        }
        // El secreto de integridad equivocado produce "signature: La firma es inválida"
        // ya dentro del checkout de Wompi: mejor frenar aquí con el motivo exacto.
        if ($errorIntegridad = $this->wompi->validarIntegridad()) {
            return response()->json(['message' => 'La pasarela de pagos no está bien configurada: ' . $errorIntegridad], 422);
        }
        return null;
    }

    /** Crea la sesión de pago (Wompi) para pagar o renovar la membresía mensual de un plan. */
    public function createPlanSession(Request $request, Plan $plan)
    {
        $user = $request->user();

        if (! $plan->activo || (int) $plan->precio_mensual <= 0) {
            return response()->json(['message' => 'Este plan no está disponible para pago en línea.'], 422);
        }

        $pt = PaymentTransaction::create([
            'provider' => 'wompi',
            'status' => 'PENDING',
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'payload' => ['tipo' => 'membresia', 'plan_id' => $plan->id, 'plan' => $plan->nombre, 'user_id' => $user->id],
            'amount' => $plan->precio_mensual,
            'currency' => 'COP',
        ]);

        $referencia = "LOGIX-PLAN-{$pt->id}";
        $pt->update(['payload' => array_merge($pt->payload, ['reference' => $referencia])]);

        if ($error = $this->validarWompi()) {
            return $error;
        }

        return response()->json([
            'checkoutUrl' => $this->wompi->checkoutUrl((int) $plan->precio_mensual, $referencia),
            'payment_transaction_id' => $pt->id,
            'reference' => $referencia,
        ]);
    }
}
