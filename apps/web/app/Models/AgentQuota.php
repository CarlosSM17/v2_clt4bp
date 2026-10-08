<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'periodo', 'limite_usd', 'usado_usd'])]
class AgentQuota extends Model
{
    protected function casts(): array
    {
        return ['limite_usd' => 'float', 'usado_usd' => 'float'];
    }

    /** Cuota del mes en curso; se crea con el límite por defecto la primera vez. */
    public static function delMes(User|int $usuario): self
    {
        return self::firstOrCreate(
            ['user_id' => $usuario instanceof User ? $usuario->id : $usuario, 'periodo' => now()->format('Y-m')],
            ['limite_usd' => config('clt4bp.agente.limite_mensual_usd'), 'usado_usd' => 0],
        );
    }

    public function agotada(): bool
    {
        return $this->usado_usd >= $this->limite_usd;
    }
}
