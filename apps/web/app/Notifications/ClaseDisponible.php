<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Aviso al estudiante: se abrió una clase de tareas de su curso. */
class ClaseDisponible extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $cursoId,
        public readonly string $curso,
        public readonly string $claseUid,
        public readonly string $clase,
        public readonly ?string $cierra,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject("Ya puedes empezar: {$this->clase}")
            ->greeting("Hola, {$notifiable->name}")
            ->line("En «{$this->curso}» se abrió la clase de tareas «{$this->clase}».")
            ->line($this->cierra ? "Tienes hasta el {$this->cierra} para enviar tus tareas." : 'Empieza por la información de «Antes de empezar».')
            ->action('Ir a la clase', route('aula.clase', ['curso' => $this->cursoId, 'clase' => $this->claseUid]));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'tipo' => 'clase_disponible',
            'texto' => "Se abrió «{$this->clase}» en {$this->curso}.",
            'url' => route('aula.clase', ['curso' => $this->cursoId, 'clase' => $this->claseUid], false),
        ];
    }
}
