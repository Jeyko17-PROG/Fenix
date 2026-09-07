<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = [
        'nombre',
        'precio_mensual',
        'limite_clientes',
        'limite_citas',
        'incluye',
        'funcionalidades',
        'activo',
        'orden',
    ];

    protected $casts = [
        'incluye' => 'array',
        'funcionalidades' => 'array',
        'activo' => 'boolean',
        'precio_mensual' => 'integer',
        'limite_clientes' => 'integer',
        'limite_citas' => 'integer',
    ];

    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class, 'plan_id');
    }
}
