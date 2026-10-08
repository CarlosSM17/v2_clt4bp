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

    public function toMail(User $notifiable): MailMessage
    {
        $horas = config('clt4bp.invitacion_horas');
        $url = URL::temporarySignedRoute('invitacion.show', now()->addHours($horas), ['user' => $notifiable->id]);

        return (new MailMessage)
            ->subject('Invitación como instructor en '.config('app.name'))
            ->greeting("Hola, {$notifiable->name}")
            ->line('Te dieron de alta como instructor. Define tu contraseña para activar la cuenta.')
            ->action('Definir mi contraseña', $url)
            ->line("El enlace vence en {$horas} horas. Después deberás activar la verificación en dos pasos.");
    }
}
