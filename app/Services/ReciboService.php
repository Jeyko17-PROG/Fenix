<?php

namespace App\Services;

use App\Models\Factura;
use App\Support\StorageUrl;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Generación y envío del PDF de una factura. Centraliza lo que antes vivía
 * duplicado en FacturaController: cualquier flujo de cobro (POS, caja por
 * placa, comandas de restaurante) genera y envía el mismo recibo.
 */
class ReciboService
{
    public function __construct(private Notificador $notificador, private NodeRenderService $renderer) {}

    /** Genera (o regenera) el PDF de la factura y guarda su URL. */
    public function generarPdf(Factura $factura): string
    {
        $factura->loadMissing(['cliente', 'detalles', 'empresa']);

        $firma = null;
        if ($factura->firma_url) {
            $ruta = StorageUrl::rutaLocal($factura->firma_url);
            if (Storage::disk('public')->exists($ruta)) {
                $firma = 'data:' . Storage::disk('public')->mimeType($ruta)
                    . ';base64,' . base64_encode(Storage::disk('public')->get($ruta));
            }
        }

        $pdf = $this->renderer->pdf('factura', [
            'factura' => $factura,
            'firma' => $firma,
            'logo' => $factura->empresa?->logo_url,
        ]);

        $nombre = "facturas/{$factura->numero}_" . now()->timestamp . '.pdf';
        Storage::disk('public')->put($nombre, $pdf);
        $url = Storage::url($nombre);
        $factura->update(['pdf_url' => $url]);

        return $url;
    }

    /**
     * Envía el recibo al correo del cliente (si tiene) en segundo plano.
     * Nunca lanza excepciones: un fallo de correo no debe tumbar el cobro.
     */
    public function enviarPorCorreo(Factura $factura): void
    {
        try {
            $factura->loadMissing('cliente', 'empresa');
            $email = $factura->cliente?->email;
            if (! $email) {
                return;
            }

            if (! $factura->pdf_url) {
                $this->generarPdf($factura);
                $factura->refresh();
            }

            $adjunto = $factura->pdf_url
                ? Storage::disk('public')->path(StorageUrl::rutaLocal($factura->pdf_url))
                : null;

            // Si la empresa configuró su propio correo de facturación, la factura
            // sale a nombre de ella (From/Reply-To) en vez del remitente genérico.
            $empresa = $factura->empresa;

            // Cotización (BORRADOR): mismo PDF, pero el texto del correo no debe
            // sonar a "ya compraste" - todavía no cuenta como venta.
            $esCotizacion = $factura->estado === 'BORRADOR';
            $etiqueta = $esCotizacion ? 'Cotización' : 'Factura';

            $this->notificador->correo(
                $email,
                "{$etiqueta} {$factura->numero} - Fénix",
                "{$etiqueta} {$factura->numero}",
                [
                    "Hola {$factura->cliente->nombre_completo},",
                    $esCotizacion
                        ? "Te compartimos la cotización {$factura->numero} por un total de \${$factura->total}."
                        : "Gracias por tu compra. Adjuntamos tu factura {$factura->numero} por un total de \${$factura->total}.",
                    'Si tienes alguna duda, responde a este correo.',
                ],
                $adjunto,
                'FACTURA',
                $empresa?->email_facturacion,
                $empresa?->nombre,
            );
        } catch (\Throwable $e) {
            Log::warning('Fallo el envío automático de la factura', [
                'factura' => $factura->numero,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
