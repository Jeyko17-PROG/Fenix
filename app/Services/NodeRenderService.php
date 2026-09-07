<?php

namespace App\Services;

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
}
