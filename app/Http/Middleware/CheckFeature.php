<?php

namespace App\Http\Middleware;

use App\Support\Funcionalidades;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckFeature
{
    /**
     * Controla el acceso a un módulo según su estado para el usuario.
     * Uso: ->middleware('feature:clientes')
     *
     *  - ACTIVADA: acceso normal.
     *  - RESTRINGIDA: solo lectura (GET/HEAD); las acciones de escritura se bloquean.
     *  - DESACTIVADA: sin acceso.
     */
    public function handle(Request $request, Closure $next, string $clave): Response
    {
        $user = $request->user();

        if ($user) {
            $estado = Funcionalidades::estadoEfectivo($user, $clave);

            if ($estado === 'ACTIVADA') {
                return $next($request);
            }

            if ($estado === 'RESTRINGIDA' && $request->isMethodSafe()) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'Esta funcionalidad no está disponible para su cuenta.',
            'funcionalidad_bloqueada' => $clave,
        ], 403);
    }
}
