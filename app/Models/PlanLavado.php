<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAUsuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PlanLavado extends Model
{
    use PerteneceAUsuario, SoftDeletes;

    protected $table = 'planes_lavado';

    protected $fillable = [
        'owner_id', 'nombre', 'descripcion', 'precio', 'duracion_min',
        'aplica_moto', 'aplica_carro', 'icono', 'orden', 'activo',
    ];

    protected $casts = [
        'precio' => 'decimal:2',
        'duracion_min' => 'integer',
        'aplica_moto' => 'boolean',
        'aplica_carro' => 'boolean',
        'orden' => 'integer',
        'activo' => 'boolean',
    ];
}
