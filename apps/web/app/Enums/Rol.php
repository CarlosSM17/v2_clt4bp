<?php

namespace App\Enums;

enum Rol: string
{
    case Admin = 'admin';
    case Instructor = 'instructor';
    case Estudiante = 'estudiante';
}
