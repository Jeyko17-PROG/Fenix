<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Renderiza los PDFs (factura, orden de compra, recibo de pago) y el correo
 * genérico desde plantillas React, vía los CLIs compilados en pdf-render/dist
 * (reemplaza a dompdf/Blade; requiere `npm install && npm run build` en
 * pdf-render/ antes de usarse).
 */
class NodeRenderService
{
    private string $basePath;

    public function __construct()
    {
        $this->basePath = base_path('pdf-render');
    }

    /** @param 'factura'|'orden_compra'|'recibo_pago' $template */
    public function pdf(string $template, array $data): string
    {
        return $this->run('dist/render-pdf.cjs', $data, $template);
    }

    public function email(array $data): string
    {
        return $this->run('dist/render-email.cjs', $data);
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
