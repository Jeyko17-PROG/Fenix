<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\MessageConverter;

/**
 * Envío de correo por la API HTTP de Brevo (api.brevo.com).
 *
 * Render bloquea las conexiones SMTP salientes (puertos 25/465/587), así que
 * el correo transaccional debe salir por HTTPS. Brevo ofrece 300 correos/día
 * gratis y no exige dominio propio: basta verificar el correo remitente.
 *
 * Configuración: MAIL_MAILER=brevo + BREVO_API_KEY=xkeysib-... y
 * MAIL_FROM_ADDRESS = el remitente verificado en Brevo.
 */
class BrevoApiTransport extends AbstractTransport
{
    public function __construct(private string $apiKey)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $from = $email->getFrom()[0] ?? null;
        $payload = [
            'sender' => [
                'email' => $from?->getAddress() ?? config('mail.from.address'),
                'name' => $from?->getName() ?: config('mail.from.name', 'Fénix'),
            ],
            'to' => array_map(fn ($a) => ['email' => $a->getAddress()], $email->getTo()),
            'subject' => (string) $email->getSubject(),
            'htmlContent' => $email->getHtmlBody() ?? nl2br(e((string) $email->getTextBody())),
        ];

        foreach ($email->getAttachments() as $adjunto) {
            $nombre = $adjunto->getPreparedHeaders()
                ->getHeaderParameter('Content-Disposition', 'filename') ?: 'adjunto.pdf';
            $payload['attachment'][] = [
                'name' => $nombre,
                'content' => base64_encode($adjunto->getBody()),
            ];
        }

        $res = Http::withHeaders(['api-key' => $this->apiKey, 'accept' => 'application/json'])
            ->timeout(20)
            ->post('https://api.brevo.com/v3/smtp/email', $payload);

        if ($res->failed()) {
            throw new TransportException('Brevo: ' . $res->status() . ' ' . $res->body());
        }
    }

    public function __toString(): string
    {
        return 'brevo-api';
    }
}
