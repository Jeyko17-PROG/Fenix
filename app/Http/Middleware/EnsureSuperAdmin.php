<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    /**
     * Permite el acceso únicamente al Super Administrador de la plataforma.
     * Uso en rutas: ->middleware('superadmin')
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->esSuperAdmin()) {
            return response()->json([
                'message' => 'Solo el Super Administrador puede acceder a este recurso.',
            ], 403);
        }

        return $next($request);
    }
}
