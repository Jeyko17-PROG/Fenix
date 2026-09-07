<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Restringe el acceso a usuarios con alguno de los roles indicados.
     * Uso en rutas: ->middleware('role:Administrador,Almacenista')
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        // El super-admin y el propietario del workspace tienen acceso total a sus datos.
        // Los demás roles (Almacenista, Ventas/Compras, Empleado) deben estar en la lista.
        if ($user && ($user->esPropietario() || $user->tieneRol(...$roles))) {
            return $next($request);
        }

        return response()->json([
            'message' => 'No tienes permiso para acceder a este recurso.',
        ], 403);
    }
}
