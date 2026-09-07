<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Auth\AuthenticationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Normaliza números en formato colombiano (400.000 -> 400000) en campos
        // monetarios de TODA la API, antes de validar.
        $middleware->api(append: [
            \App\Http\Middleware\NormalizarNumerosLocales::class,
        ]);

        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
            'superadmin' => \App\Http\Middleware\EnsureSuperAdmin::class,
            'feature' => \App\Http\Middleware\CheckFeature::class,
            'membresia' => \App\Http\Middleware\VerificarMembresia::class,
        ]);

        // Evita que las peticiones de la API o del navegador hacia rutas protegidas
        // busquen la ruta 'login'. En su lugar, simplemente detenemos la redirección.
        $middleware->redirectGuestsTo(fn (Request $request) => null);

        // Render (y cualquier PaaS con proxy inverso) termina el HTTPS en su borde
        // y reenvía la petición como HTTP puro a la app. Sin confiar en el proxy,
        // Request::isSecure()/url() creen que todo es HTTP y generan enlaces http://
        // (rompiendo el redirect_uri de Google OAuth y cualquier URL absoluta).
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => true, // Forzamos JSON siempre para evitar pantallas naranjas de error
        );

        // Controlamos la excepción de falta de autenticación de forma limpia
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json([
                'error' => 'No autorizado',
                'message' => 'Debes iniciar sesión en tu cuenta de Logix primero para poder conectar Gmail.'
            ], 401);
        });
    })->create();