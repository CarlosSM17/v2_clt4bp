<?php

namespace App\Notifications;

use App\Models\Course;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InscripcionAprobada extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Course $curso) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Ya estás inscrito en {$this->curso->titulo}")
            ->line('Tu instructor aprobó tu inscripción. El primer paso es el diagnóstico inicial.')
            ->action('Ir al curso', route('cursos.show', $this->curso));
    }

    public function toArray(object $notifiable): array
    {
        return ['tipo' => 'inscripcion_aprobada', 'course_id' => $this->curso->id, 'titulo' => $this->curso->titulo];
    }
}
