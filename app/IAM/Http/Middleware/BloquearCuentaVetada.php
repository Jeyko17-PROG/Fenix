<?php

namespace App\IAM\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cierre total de acceso a una cuenta específica, a pedido explícito del
 * dueño de la plataforma — no solo bloquea el login (AuthController::login),
 * sino cualquier petición autenticada con un token ya emitido antes de este
 * bloqueo. Revoca el token en el momento en que se detecta, así no sirve ni
 * una vez más.
 */
class BloquearCuentaVetada
{
    private const CORREO_VETADO = 'andres52885241@gmail.com';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->email === self::CORREO_VETADO) {
            $request->user()->currentAccessToken()?->delete();

            return response()->json([
                'message' => 'Este sistema es solo para gente leal, Andrés Gutiérrez Hurtado.',
            ], 403);
        }

        return $next($request);
    }
}
