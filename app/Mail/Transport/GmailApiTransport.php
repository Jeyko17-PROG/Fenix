<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * Envío de correo por la API HTTP de Gmail (puerto 443).
 *
 * Render bloquea el SMTP saliente, así que el correo sale por HTTPS usando
 * la API oficial de Google. Requiere OAuth2 (Google no permite usar la App
 * Password de SMTP en su API):
 *   1. En console.cloud.google.com (misma cuenta Gmail): habilitar "Gmail API"
 *      y crear credenciales OAuth (tipo Web) con la redirect URI
 *      {APP_URL}/api/gmail/callback
 *   2. Definir GOOGLE_CLIENT_ID y GOOGLE_CLIENT_SECRET en el servidor.
 *   3. El super-admin abre /api/admin/gmail/conectar y autoriza con un clic:
 *      el refresh token queda guardado en la BD (cache) y todo queda automático.
 */
class GmailApiTransport extends AbstractTransport
{
    public const CACHE_REFRESH_TOKEN = 'gmail_refresh_token';
    private const CACHE_ACCESS_TOKEN = 'gmail_access_token';

    protected function doSend(SentMessage $message): void
    {
        $token = $this->accessToken();

        // La API recibe el mensaje MIME completo (con adjuntos) en base64 URL-safe.
        $raw = rtrim(strtr(base64_encode($message->toString()), '+/', '-_'), '=');

        $res = Http::withToken($token)->timeout(25)
            ->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', ['raw' => $raw]);

        if ($res->failed()) {
            throw new TransportException('Gmail API: ' . $res->status() . ' ' . $res->body());
        }
    }

    /** Refresh token: variable de entorno o el guardado por el flujo de autorización. */
    public static function refreshToken(): ?string
    {
        $env = (string) config('services.gmail.refresh_token');
        if ($env !== '') {
            return $env;
        }
        try {
            return Cache::get(self::CACHE_REFRESH_TOKEN);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** ¿Hay credenciales + autorización suficientes para enviar? */
    public static function configurado(): bool
    {
        return ! empty(config('services.gmail.client_id'))
            && ! empty(config('services.gmail.client_secret'))
            && ! empty(static::refreshToken());
    }

    /** Access token vigente (se renueva solo con el refresh token; cache ~55 min). */
    private function accessToken(): string
    {
        $token = Cache::get(self::CACHE_ACCESS_TOKEN);
        if ($token) {
            return $token;
        }

        $refresh = static::refreshToken();
        if (! $refresh) {
            throw new TransportException('Gmail API: falta autorizar la cuenta (abre /api/admin/gmail/conectar como super-admin).');
        }

        $res = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.gmail.client_id'),
            'client_secret' => config('services.gmail.client_secret'),
            'refresh_token' => $refresh,
            'grant_type' => 'refresh_token',
        ]);

        if ($res->failed() || ! $res->json('access_token')) {
            throw new TransportException('Gmail API (token): ' . $res->status() . ' ' . $res->body());
        }

        $token = $res->json('access_token');
        Cache::put(self::CACHE_ACCESS_TOKEN, $token, max(60, (int) $res->json('expires_in', 3600) - 300));

        return $token;
    }

    public function __toString(): string
    {
        return 'gmail-api';
    }
}
