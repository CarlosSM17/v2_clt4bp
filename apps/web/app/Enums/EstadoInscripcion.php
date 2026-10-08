<?php

namespace App\Enums;

enum EstadoInscripcion: string
{
    case Solicitud = 'solicitud';
    case Rechazada = 'rechazada';
    case Inscrito = 'inscrito';
    case Diagnostico = 'diagnostico';
    case ConPerfil = 'con_perfil';
    case Cursando = 'cursando';
    case EvaluacionFinal = 'evaluacion_final';
    case Concluido = 'concluido';
    case Baja = 'baja';

    /** Estados en los que el estudiante puede entrar al curso. */
    public function daAcceso(): bool
    {
        return in_array($this, [
            self::Inscrito, self::Diagnostico, self::ConPerfil,
            self::Cursando, self::EvaluacionFinal, self::Concluido,
        ], true);
    }
}
