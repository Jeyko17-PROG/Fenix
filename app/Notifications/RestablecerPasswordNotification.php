<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RestablecerPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(public string $url) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Restablece tu contraseña — Fénix')
            ->greeting('Hola ' . ($notifiable->name ?? '') . ',')
            ->line('Recibimos una solicitud para restablecer la contraseña de tu cuenta Fénix.')
            ->action('Restablecer contraseña', $this->url)
            ->line('Este enlace caduca en 60 minutos.')
            ->line('Si no solicitaste el cambio, puedes ignorar este correo.');
    }
}
