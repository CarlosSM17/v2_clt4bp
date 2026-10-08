<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Correo de la revisión automática (7.5). No va a la cola a propósito: si la alerta es que la
 * cola está detenida, un aviso encolado nunca saldría.
 */
class AlertaOperacion extends Notification
{
    /** @param list<array{clave: string, texto: string}> $problemas */
    public function __construct(public readonly array $problemas) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $correo = (new MailMessage())
            ->subject('CLT4BP: '.count($this->problemas).' alerta(s) de operación')
            ->line('La revisión automática de '.config('app.url').' encontró:');
        foreach ($this->problemas as $p) {
            $correo->line('- '.$p['texto']);
        }

        return $correo->line('Cada problema se vuelve a avisar como máximo una vez por hora mientras siga.');
    }
}
