<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAUsuario;
use App\Models\Scopes\OwnerScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AjusteAgenda extends Model
{
    use PerteneceAUsuario;

    protected $table = 'ajustes_agenda';

    protected $fillable = ['owner_id', 'duracion_cita_min', 'buffer_min'];

    protected $casts = [
        'duracion_cita_min' => 'integer',
        'buffer_min' => 'integer',
    ];

    /** Ajustes de la agenda del usuario/negocio indicado (los crea si no existen). */
    public static function actual(?int $ownerId = null): self
    {
        $ownerId ??= Auth::id();

        return static::withoutGlobalScope(OwnerScope::class)
            ->firstOrCreate(['owner_id' => $ownerId], ['duracion_cita_min' => 30, 'buffer_min' => 0]);
    }
}
