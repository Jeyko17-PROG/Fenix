<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAUsuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HorarioLaboral extends Model
{
    use PerteneceAUsuario;

    protected $table = 'horarios_laborales';

    protected $fillable = ['owner_id', 'bodega_id', 'dia_semana', 'hora_inicio', 'hora_fin', 'activo'];

    protected $casts = [
        'dia_semana' => 'integer',
        'activo' => 'boolean',
    ];

    /** Sucursal a la que aplica. Nulo = horario general de toda la empresa. */
    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class, 'bodega_id');
    }
}
