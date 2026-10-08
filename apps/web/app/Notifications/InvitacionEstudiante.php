<?php

namespace App\Notifications;

use App\Models\Course;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvitacionEstudiante extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Course $curso) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Te invitan al curso {$this->curso->titulo}")
            ->line("Regístrate en la plataforma y solicita tu inscripción con el código {$this->curso->codigo_inscripcion}.")
            ->action('Registrarme', route('register'));
    }
}
