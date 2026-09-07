<?php

namespace App\Http\Controllers;

/**
 * Sirve el shell HTML de la SPA de React (resources/frontend) sin usar
 * Blade: es HTML puro generado en PHP, no una plantilla de plantillas.
 * react-router-dom hace el ruteo real en el navegador; esta es la única
 * página que Laravel entrega para cualquier ruta que no sea /api,
 * /storage o /build (ver routes/web.php).
 */
class SpaController extends Controller
{
    public function index()
    {
        return response($this->html(), 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    private function html(): string
    {
        [$scripts, $styles] = $this->assets();

        return <<<HTML
        <!doctype html>
        <html lang="es">
          <head>
            <meta charset="UTF-8" />
            <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png" />
            <link rel="icon" type="image/png" sizes="64x64" href="/favicon.png" />
            <link rel="icon" type="image/png" sizes="192x192" href="/pwa-192x192.png" />
            <link rel="apple-touch-icon" href="/apple-touch-icon.png" />
            <link rel="manifest" href="/build/manifest.webmanifest" />
            <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
            <meta name="theme-color" content="#e04a0a" />
            <meta name="description" content="Fénix · Velocidad y eficiencia en tu punto de venta. Gestión de clientes, inventario, facturación y reservas." />
            <meta name="apple-mobile-web-app-capable" content="yes" />
            <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
            <meta name="apple-mobile-web-app-title" content="Fénix" />
            <meta name="mobile-web-app-capable" content="yes" />
            <title>Fénix · Velocidad y eficiencia en tu punto de venta</title>
            {$styles}
            {$scripts}
          </head>
          <body>
            <div id="root"></div>
          </body>
        </html>
        HTML;
    }

    /**
     * Resuelve los <script>/<link> del frontend sin pasar por @vite() de
     * Blade: en desarrollo (`npm run dev` corriendo) apunta al servidor de
     * Vite para hot-reload; en producción lee el manifest.json que genera
     * el build para encontrar los archivos con hash del momento.
     *
     * @return array{0: string, 1: string} [scripts, styles]
     */
    private function assets(): array
    {
        $hotFile = public_path('hot');

        if (file_exists($hotFile)) {
            $viteServer = rtrim(file_get_contents($hotFile));
            return [
                <<<HTML
                <script type="module" src="{$viteServer}/@vite/client"></script>
                <script type="module" src="{$viteServer}/src/main.jsx"></script>
                HTML,
                '',
            ];
        }

        $manifestPath = public_path('build/manifest.json');
        if (! file_exists($manifestPath)) {
            abort(500, 'Frontend sin compilar: falta public/build/manifest.json (corre "npm run build" en resources/frontend).');
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        $entry = $manifest['src/main.jsx'] ?? null;
        if (! $entry) {
            abort(500, 'El manifest de Vite no tiene la entrada "src/main.jsx".');
        }

        $scripts = '<script type="module" src="/build/' . $entry['file'] . '"></script>';
        $styles = '';
        foreach ($entry['css'] ?? [] as $css) {
            $styles .= '<link rel="stylesheet" href="/build/' . $css . '" />' . "\n    ";
        }

        return [$scripts, $styles];
    }
}
