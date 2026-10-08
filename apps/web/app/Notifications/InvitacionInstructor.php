<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class InvitacionInstructor extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * El enlace firmado para definir la contraseña. Lo lleva el correo y también lo copia el administrador desde la
     * consola, para entregarlo por otro medio cuando el correo no puede salir.
     */
    public static function enlace(User $instructor): string
    {
        return URL::temporarySignedRoute('invitacion.show', now()->addHours(config('clt4bp.invitacion_horas')), ['user' => $instructor->id]);
    }

    public function toMail(User $notifiable): MailMessage
    {
        $horas = config('clt4bp.invitacion_horas');
        $url = self::enlace($notifiable);

        return (new MailMessage)
            ->subject('Invitación como instructor en '.config('app.name'))
            ->greeting("Hola, {$notifiable->name}")
            ->line('Te dieron de alta como instructor. Define tu contraseña para activar la cuenta.')
            ->action('Definir mi contraseña', $url)
            ->line("El enlace vence en {$horas} horas. Después deberás activar la verificación en dos pasos.");
    }
}
