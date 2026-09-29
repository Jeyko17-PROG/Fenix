<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

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
            \App\Shared\Http\Middleware\NormalizarNumerosLocales::class,
        ]);

        $middleware->alias([
            'role' => \App\IAM\Http\Middleware\EnsureUserHasRole::class,
            'superadmin' => \App\IAM\Http\Middleware\EnsureSuperAdmin::class,
            'feature' => \App\IAM\Http\Middleware\CheckFeature::class,
            'membresia' => \App\Billing\Http\Middleware\VerificarMembresia::class,
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

        // Límite de intentos (throttle) en rutas públicas de autenticación: el
        // mensaje por defecto de Laravel ("Too Many Attempts.") sale en inglés
        // y sin explicar qué hacer - aquí se traduce y se le dice al usuario
        // cuántos segundos esperar.
        $exceptions->render(function (\Illuminate\Http\Exceptions\ThrottleRequestsException $e, Request $request) {
            $segundos = $e->getHeaders()['Retry-After'] ?? null;
            return response()->json([
                'message' => $segundos
                    ? "Demasiados intentos. Espera {$segundos} segundos y vuelve a intentarlo."
                    : 'Demasiados intentos. Espera un momento y vuelve a intentarlo.',
            ], 429, $e->getHeaders());
        });

        // Un modelo no encontrado (Route::model binding / findOrFail) llega
        // aquí ya convertido a 404 por Laravel, pero con un mensaje tipo
        // "No query results for model [App\Billing\...\Factura] 999" que
        // expone la clase Eloquent interna. Se limpia solo ese caso puntual;
        // los abort(404, '...') con mensaje propio del código no se tocan.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, Request $request) {
            if (str_starts_with($e->getMessage() ?? '', 'No query results for model')) {
                return response()->json(['message' => 'El recurso solicitado no existe.'], 404);
            }
            return null;
        });

        // Errores de base de datos (SQL, conexión, constraint): el mensaje
        // real trae la consulta y hasta credenciales del driver - eso NUNCA
        // debe llegar al cliente (ver captura de "no filtrar detalles de BD").
        // Se registra completo en el log del servidor y al cliente solo baja
        // un mensaje genérico, sin importar el valor de APP_DEBUG.
        $exceptions->render(function (QueryException $e, Request $request) {
            Log::error('Error de base de datos', [
                'url' => $request->fullUrl(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Ocurrió un problema al procesar tu solicitud. Intenta de nuevo en un momento.',
            ], 500);
        });

        // Red de seguridad final: cualquier otra excepción no controlada
        // (fuera de las HTTP normales que Laravel ya sabe mostrar, como 404
        // o 422) tampoco debe filtrar rutas de archivos, clases internas ni
        // trazas al cliente. Se loguea completo y se responde genérico.
        $exceptions->render(function (\Throwable $e, Request $request) {
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                || $e instanceof \Illuminate\Validation\ValidationException) {
                return null; // deja que Laravel maneje 404/403/422/etc. como siempre.
            }

            Log::error('Error no controlado', [
                'url' => $request->fullUrl(),
                'exception' => get_class($e),
                'error' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);

            return response()->json([
                'message' => 'Ocurrió un error inesperado. Ya quedó registrado; intenta de nuevo en un momento.',
            ], 500);
        });
    })->create();