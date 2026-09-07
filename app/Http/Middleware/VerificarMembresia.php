<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloquea las funciones operativas del POS cuando la membresía mensual venció.
 *
 * - Aplica al dueño del workspace (los empleados quedan bloqueados si su dueño no pagó).
 * - Solo bloquea en modo 'membresia' con fecha de vencimiento pasada; el modo 'prepago'
 *   se cobra por factura y no se bloquea aquí.
 * - Deja pasar las rutas de cuenta/pago para que el usuario pueda renovar
 *   (perfil, planes, créditos/recargas, notificaciones, logout).
 * - Responde 402 con codigo MEMBRESIA_VENCIDA: el frontend debe mostrar la pasarela de pago.
 */
class VerificarMembresia
{
    /** Prefijos de ruta (relativos a api/) que siguen disponibles con la membresía vencida. */
    private const RUTAS_PERMITIDAS = [
        'api/me',
        'api/logout',
        'api/perfil',
        'api/planes',
        'api/credit-packages',
        'api/credits',
        'api/mis-funcionalidades',
        'api/notificaciones',
        'api/admin', // panel del super-admin (gestiona las licencias)
        'api/cuenta', // "Mis negocios": debe poder alternar aunque este negocio tenga la membresía vencida
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || $user->esSuperAdmin()) {
            return $next($request);
        }

        foreach (self::RUTAS_PERMITIDAS as $prefijo) {
            if ($request->is($prefijo) || $request->is($prefijo . '/*')) {
                return $next($request);
            }
        }

        $owner = $user->billingOwner();
        if ($owner->esSuperAdmin() || ! $owner->membresiaVencida()) {
            return $next($request);
        }

        $this->alertarPruebaVencida($owner);

        return response()->json([
            'codigo' => 'MEMBRESIA_VENCIDA',
            'message' => 'Tu membresía venció. Renueva tu plan para seguir usando el POS.',
            'vencio_el' => $owner->membresia_vence_at?->toDateString(),
            'plan' => $owner->planEfectivo()?->nombre,
        ], 402);
    }

    /**
     * Avisa una sola vez al super-admin cuando una prueba gratuita termina
     * sin pago (empresa_id.prueba_alerta_enviada evita repetir el aviso en
     * cada request bloqueado).
     */
    private function alertarPruebaVencida($owner): void
    {
        // La prueba (y el aviso de una sola vez) se rastrea en la empresa
        // GOBERNANTE del grupo, no en el negocio puntual sobre el que se
        // hizo la petición — así no se repite el aviso por cada negocio
        // vinculado que comparte la misma prueba/plan.
        $empresa = $owner->empresaDeCobro()?->empresaGobernante();
        if (! $empresa || $empresa->modo_cobro !== 'prueba' || $empresa->prueba_alerta_enviada) {
            return;
        }

        $empresa->forceFill(['prueba_alerta_enviada' => true])->save();

        $superAdmin = \App\Models\User::where('es_super_admin', true)->first();
        if (! $superAdmin) {
            return;
        }

        app(\App\Services\Notificador::class)->aUsuario(
            $superAdmin->id,
            'ADMIN',
            'Prueba gratuita finalizada',
            "\"{$empresa->nombre}\" (dueño: {$owner->name} · {$owner->email}) terminó su prueba gratuita de 15 días y aún no ha pagado."
        );
    }
}
