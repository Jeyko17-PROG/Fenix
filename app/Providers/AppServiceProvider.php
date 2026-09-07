<?php

namespace App\Providers;

use App\Mail\Transport\BrevoApiTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Respaldo explícito: aunque trustProxies (bootstrap/app.php) ya debería
        // detectar HTTPS vía X-Forwarded-Proto, forzamos el esquema en producción
        // para que url()/route() nunca generen enlaces http:// (rompe el OAuth de
        // Google y cualquier enlace absoluto en correos/PDFs).
        if (config('app.env') === 'production') {
            URL::forceScheme('https');
        }

        // Transportes de correo por API HTTP (Render bloquea el SMTP saliente).
        Mail::extend('brevo-api', function () {
            return new BrevoApiTransport((string) config('services.brevo.key'));
        });
        Mail::extend('gmail-api', function () {
            return new \App\Mail\Transport\GmailApiTransport();
        });

        // Selección automática del mejor transporte disponible, sin tocar MAIL_MAILER:
        //   1. Gmail API (si hay OAuth de Google autorizado)
        //   2. Brevo (si hay BREVO_API_KEY)
        //   3. lo que diga MAIL_MAILER (smtp/log)
        try {
            if (\App\Mail\Transport\GmailApiTransport::configurado()) {
                config(['mail.default' => 'gmail']);
            } elseif (config('services.brevo.key') && config('mail.default') !== 'brevo') {
                config(['mail.default' => 'brevo']);
            }
        } catch (\Throwable $e) {
            // BD/cache no disponible aún (build, migraciones): se mantiene el default.
        }
    }
}
