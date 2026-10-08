<?php

namespace App\Enums;

enum EstadoCurso: string
{
    case Diseno = 'diseno';
    case Activo = 'activo';
    case Concluido = 'concluido';
    case Archivado = 'archivado';
}
