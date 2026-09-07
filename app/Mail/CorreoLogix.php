<?php

namespace App\Mail;

use App\Services\NodeRenderService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Correo genérico de Logix basado en una plantilla.
 * Sirve para: bienvenida, confirmación de cita, recordatorio, factura, etc.
 */
class CorreoLogix extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $asunto,
        public string $titulo,
        public array $lineas = [],
        public ?string $adjuntoPath = null,
        // Remitente propio de la empresa (ej. facturas): si viene vacío, se usa
        // el remitente global de config('mail.from'). Ver Notificador::correo().
        public ?string $fromEmail = null,
        public ?string $fromName = null,
    ) {}

    public function envelope(): Envelope
    {
        $envelope = new Envelope(
            subject: $this->asunto,
        );

        // Remitente propio de la empresa (ej. facturas): si no se inyectó desde
        // Notificador::correo(), el mensaje conserva el remitente global de
        // config('mail.from') sin tocar el Envelope (compatibilidad total).
        if (! empty($this->fromEmail)) {
            $displayName = $this->fromName ?? config('mail.from.name', 'Facturación');
            $remitente = new Address($this->fromEmail, $displayName);

            $envelope->from = $remitente;
            $envelope->replyTo = [$remitente];
        }

        return $envelope;
    }

    public function content(): Content
    {
        $html = app(NodeRenderService::class)->email([
            'titulo' => $this->titulo,
            'lineas' => $this->lineas,
        ]);

        return new Content(htmlString: $html);
    }

    public function attachments(): array
    {
        if ($this->adjuntoPath && file_exists($this->adjuntoPath)) {
            return [\Illuminate\Mail\Mailables\Attachment::fromPath($this->adjuntoPath)];
        }
        return [];
    }
}
