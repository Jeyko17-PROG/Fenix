<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WompiService
{
    /**
     * Llave pública saneada: sin espacios, saltos de línea ni comillas
     * pegadas al copiar/pegar en el dashboard de Render.
     */
    public function publicKey(): ?string
    {
        $key = config('services.wompi.public_key');
        $key = trim((string) $key, " \t\n\r\"'");
        return $key !== '' ? $key : null;
    }

    private function secreto(string $nombre): ?string
    {
        $valor = trim((string) config("services.wompi.{$nombre}"), " \t\n\r\"'");
        return $valor !== '' ? $valor : null;
    }

    public function configurado(): bool
    {
        return $this->publicKey() !== null;
    }

    /** true si la llave es de pruebas (pub_test_...). */
    public function esSandbox(): bool
    {
        return str_starts_with((string) $this->publicKey(), 'pub_test');
    }

    /**
     * Verifica contra la API de Wompi que la llave pública corresponde a un
     * comercio válido. Evita mandar al cliente a un checkout roto
     * ("No se pudo cargar la información del undefined").
     * El resultado se cachea 10 minutos. Si la API no responde, no bloquea.
     */
    public function verificarComercio(): array
    {
        $key = $this->publicKey();
        if (! $key) {
            return ['ok' => false, 'error' => 'Sin llave pública configurada.'];
        }

        return Cache::remember("wompi_comercio_{$key}", 600, function () use ($key) {
            $base = $this->esSandbox() ? 'https://sandbox.wompi.co' : 'https://production.wompi.co';
            try {
                $res = Http::timeout(8)->get("{$base}/v1/merchants/{$key}");
                if ($res->successful()) {
                    return ['ok' => true, 'nombre' => $res->json('data.name'), 'sandbox' => $this->esSandbox()];
                }

                // Incluye el motivo exacto que devuelve Wompi para poder autodiagnosticar
                // (ej: "Formato inválido" = llave mal copiada; 404 = llave de otro ambiente).
                $detalle = $res->json('error.reason')
                    ?? collect($res->json('error.messages') ?? [])->flatten()->implode(' ')
                    ?: "HTTP {$res->status()}";

                Log::warning('Wompi: la llave pública no corresponde a un comercio válido', [
                    'status' => $res->status(), 'sandbox' => $this->esSandbox(), 'detalle' => $detalle,
                ]);

                $ambiente = $this->esSandbox() ? 'pruebas (sandbox)' : 'producción';
                return [
                    'ok' => false,
                    'error' => "Wompi no reconoce la llave pública en el ambiente de {$ambiente}: {$detalle}. "
                        . 'Revisa que WOMPI_PUBLIC_KEY esté copiada completa (pub_prod_... para producción, pub_test_... para pruebas) '
                        . 'junto con su WOMPI_INTEGRITY_SECRET del MISMO ambiente.',
                ];
            } catch (\Throwable $e) {
                Log::warning('Wompi: no se pudo verificar el comercio', ['error' => $e->getMessage()]);
                return ['ok' => true, 'nombre' => null, 'sandbox' => $this->esSandbox()]; // no bloquear por un fallo de red
            }
        });
    }

    /**
     * URL del Web Checkout de Wompi.
     * @param int    $montoPesos monto en pesos colombianos (se convierte a centavos)
     * @param string $referencia referencia única (ej. LOGIX-PLAN-15)
     */
    public function checkoutUrl(int $montoPesos, string $referencia): string
    {
        if (! $this->configurado()) {
            return $this->retornarFrontend($referencia, 'error');
        }

        $centavos = $montoPesos * 100;
        $params = [
            'public-key' => $this->publicKey(),
            'currency' => 'COP',
            'amount-in-cents' => $centavos,
            'reference' => $referencia,
        ];

        // A dónde vuelve el cliente al terminar el pago (obligatorio para una buena UX;
        // si no está configurado, se usa la pantalla de planes del frontend).
        $redirect = $this->secreto('redirect_url')
            ?? rtrim((string) env('FRONTEND_URL', ''), '/');
        if ($redirect) {
            $params['redirect-url'] = $this->retornarFrontend($referencia, 'success', $redirect);
        }

        $query = http_build_query($params);

        // Firma de integridad: sha256(referencia + centavos + moneda + secreto).
        // El nombre del parámetro lleva ':' literal (como en la documentación de
        // Wompi); http_build_query lo codificaría como %3A, así que va aparte.
        if ($secreto = $this->secreto('integrity_secret')) {
            $query .= '&signature:integrity=' . hash('sha256', $referencia . $centavos . 'COP' . $secreto);
        } else {
            Log::warning('Wompi: falta WOMPI_INTEGRITY_SECRET; el checkout puede fallar si el comercio exige firma de integridad.');
        }

        return 'https://checkout.wompi.co/p/?' . $query;
    }

    private function retornarFrontend(string $referencia, string $estado, ?string $base = null): string
    {
        $baseUrl = trim((string) ($base ?: (config('services.wompi.redirect_url') ?: env('FRONTEND_URL', config('app.url', 'http://localhost')))), " \t\n\r\"'");
        if ($baseUrl === '') {
            $baseUrl = rtrim((string) config('app.url', 'http://localhost'), '/');
        }

        $baseUrl = rtrim($baseUrl, '/');
        if (! str_contains($baseUrl, '://')) {
            $baseUrl = 'https://' . $baseUrl;
        }

        return $baseUrl . '/planes?status=' . urlencode($estado) . '&ref=' . urlencode($referencia);
    }

    /**
     * Valida que WOMPI_INTEGRITY_SECRET sea realmente el "Secreto de integridad"
     * del MISMO ambiente que la llave pública (causa típica del error de Wompi
     * "signature: La firma es inválida"). Los secretos de Wompi se distinguen
     * por prefijo: prod_integrity_/test_integrity_ (integridad),
     * prod_events_/test_events_ (eventos), prv_prod_/prv_test_ (privada).
     *
     * @return string|null null = consistente; texto = qué está mal exactamente
     */
    public function validarIntegridad(): ?string
    {
        $secreto = $this->secreto('integrity_secret');
        if (! $secreto) {
            return 'Falta WOMPI_INTEGRITY_SECRET (el "Secreto de integridad" en comercios.wompi.co → Desarrolladores).';
        }

        $esperado = $this->esSandbox() ? 'test_integrity_' : 'prod_integrity_';
        if (! str_starts_with($secreto, $esperado)) {
            $prefijo = substr($secreto, 0, 15);
            $queEs = match (true) {
                str_starts_with($secreto, 'prod_events_'), str_starts_with($secreto, 'test_events_') => 'ese es el secreto de EVENTOS (webhooks)',
                str_starts_with($secreto, 'prv_') => 'esa es la llave PRIVADA',
                str_starts_with($secreto, 'pub_') => 'esa es la llave PÚBLICA',
                str_starts_with($secreto, 'test_integrity_'), str_starts_with($secreto, 'prod_integrity_') => 'es el secreto de integridad del OTRO ambiente',
                default => 'no parece un secreto de Wompi',
            };
            return "WOMPI_INTEGRITY_SECRET incorrecto: empieza por \"{$prefijo}…\" y {$queEs}. "
                . "Para tu ambiente actual debe empezar por \"{$esperado}\". "
                . 'Cópialo de comercios.wompi.co → Desarrolladores → "Secreto de integridad".';
        }

        return null;
    }

    public function verificarEvento(array $payload): bool
    {
        $secreto = $this->secreto('events_secret');
        if (! $secreto) {
            return true;
        }

        $tx = $payload['data']['transaction'] ?? [];
        $checksum = $payload['signature']['checksum'] ?? '';
        $timestamp = $payload['timestamp'] ?? '';

        $esperado = hash('sha256',
            ($tx['id'] ?? '') . ($tx['status'] ?? '') . ($tx['amount_in_cents'] ?? '') . $timestamp . $secreto
        );

        return hash_equals($esperado, $checksum);
    }
}
