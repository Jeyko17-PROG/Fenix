<?php

namespace App\Services;

use App\Mail\CorreoLogix;
use App\Models\Notificacion;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Centraliza el envío de notificaciones internas y por correo.
 * El canal de correo usa el driver configurado (por defecto 'log' en desarrollo).
 * Estructura lista para añadir WhatsApp en el futuro.
 */
class Notificador
{
    /** Crea una notificación interna para usuarios con ciertos roles. */
    public function aRoles(array $roles, string $tipo, string $titulo, ?string $mensaje = null): void
    {
        $ids = User::whereHas('rol', fn ($q) => $q->whereIn('nombre', $roles))->pluck('id');
        foreach ($ids as $id) {
            Notificacion::create([
                'user_id' => $id, 'tipo' => $tipo, 'titulo' => $titulo,
                'mensaje' => $mensaje, 'canal' => 'INTERNA',
            ]);
        }
    }

    /** Notificación interna para un usuario específico. */
    public function aUsuario(int $userId, string $tipo, string $titulo, ?string $mensaje = null): void
    {
        Notificacion::create([
            'user_id' => $userId, 'tipo' => $tipo, 'titulo' => $titulo,
            'mensaje' => $mensaje, 'canal' => 'INTERNA',
        ]);
    }

    /**
     * Envía un correo con plantilla EN SEGUNDO PLANO (cola de trabajos):
     * la petición HTTP responde de inmediato y el worker hace el envío SMTP,
     * evitando timeouts 502 en Render cuando Gmail tarda en responder.
     * Si la cola no está disponible, cae al envío directo (síncrono).
     *
     * $fromEmail/$fromName: remitente propio de la empresa (ej. facturas). Si
     * se omiten, el correo sale con el remitente global (config('mail.from')).
     */
    public function correo(string $para, string $asunto, string $titulo, array $lineas, ?string $adjuntoPath = null, string $tipo = 'ADMIN', ?string $fromEmail = null, ?string $fromName = null): bool
    {
        try {
            Mail::to($para)->queue(new CorreoLogix($asunto, $titulo, $lineas, $adjuntoPath, $fromEmail, $fromName));
            return true;
        } catch (\Throwable $e) {
            Log::warning('No se pudo encolar el correo; intentando envío directo', ['para' => $para, 'error' => $e->getMessage()]);
            try {
                Mail::to($para)->send(new CorreoLogix($asunto, $titulo, $lineas, $adjuntoPath, $fromEmail, $fromName));
                return true;
            } catch (\Throwable $e2) {
                Log::warning('No se pudo enviar el correo de Logix', ['para' => $para, 'error' => $e2->getMessage()]);
                return false;
            }
        }
    }
}
