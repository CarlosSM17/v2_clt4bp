<?php

namespace App\Models;

use App\Domain\Instrumentos\PuntuadorLikert;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['clave', 'version', 'nombre', 'definicion'])]
class Instrument extends Model
{
    protected function casts(): array
    {
        return ['definicion' => 'array'];
    }

    public function puntuador(): PuntuadorLikert
    {
        return new PuntuadorLikert($this->definicion);
    }
}
