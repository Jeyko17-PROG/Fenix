<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catálogo de tipos de negocio (taller de motos, lavadero, tienda, restaurante...).
 * modulos_default define qué módulos del sistema aplican a ese tipo de negocio.
 */
class TipoNegocio extends Model
{
    protected $table = 'tipos_negocio';

    protected $fillable = ['clave', 'nombre', 'descripcion', 'modulos_default', 'activo', 'orden'];

    protected $casts = [
        'modulos_default' => 'array',
        'activo' => 'boolean',
    ];

    public function empresas(): HasMany
    {
        return $this->hasMany(Empresa::class, 'tipo_negocio_id');
    }
}
