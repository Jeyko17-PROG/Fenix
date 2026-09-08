<?php

namespace App\Shared\Infrastructure;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Renderiza los PDFs (factura, orden de compra, recibo de pago) y el correo
 * genérico desde plantillas React (resources/src/templates/), vía los CLIs que
 * Vite compila en bootstrap/ssr/ (reemplaza a dompdf/Blade).
 *
 * Requiere `npm install && npm run build` antes de usarse: node no ejecuta
 * .jsx, siempre corre la versión compilada. Las dependencias quedan externas
 * al bundle, así que en runtime también hace falta node_modules en la raíz.
 */
class NodeRenderService
{
    private string $basePath;

    public function __construct()
    {
        $this->basePath = base_path();
    }

    /** @param 'factura'|'orden_compra'|'recibo_pago' $template */
    public function pdf(string $template, array $data): string
    {
        return $this->run('bootstrap/ssr/render-pdf.js', $data, $template);
    }

    public function email(array $data): string
    {
        return $this->run('bootstrap/ssr/render-email.js', $data);
    }

    private function run(string $script, array $data, ?string $template = null): string
    {
        $args = array_values(array_filter(['node', $script, $template]));

        $result = Process::path($this->basePath)
            ->env($this->entornoSistema())
            ->timeout(20)
            ->input(json_encode($data))
            ->run($args);

        if ($result->failed()) {
            throw new RuntimeException(
                "Fallo el render Node ({$script} {$template}): " . $result->errorOutput()
            );
        }

        return $result->output();
    }

    /**
     * Variables del sistema que Node necesita para arrancar.
     *
     * Symfony Process arma el entorno del hijo intersecando getenv() con
     * $_SERVER, y usa $_ENV como respaldo. Con `variables_order` sin "E" (el
     * valor por defecto de php.ini) $_ENV llega vacio, y bajo un SAPI web
     * ($_SERVER son las variables de la peticion, no las del sistema) esa
     * interseccion se queda sin SystemRoot: Node aborta al iniciar con
     * "Assertion failed: ncrypto::CSPRNG(nullptr, 0)" porque OpenSSL no puede
     * sembrar el generador aleatorio. Por CLI si funciona, de ahi que fallara
     * solo desde el navegador. Se las devolvemos explicitamente.
     */
    private function entornoSistema(): array
    {
        $claves = [
            'SystemRoot', 'windir', 'SystemDrive', 'ComSpec', 'PATHEXT', // Windows
            'PATH', 'TEMP', 'TMP', 'TMPDIR', 'HOME', 'USERPROFILE',
            'APPDATA', 'LOCALAPPDATA', 'NUMBER_OF_PROCESSORS',
        ];

        $entorno = [];
        foreach ($claves as $clave) {
            $valor = getenv($clave);
            if ($valor !== false) {
                $entorno[$clave] = $valor;
            }
        }

        return $entorno;
    }
}
