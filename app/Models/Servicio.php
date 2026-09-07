<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAUsuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Servicio extends Model
{
    use PerteneceAUsuario, SoftDeletes;

    protected $table = 'servicios';

    protected $fillable = ['owner_id', 'categoria_id', 'nombre', 'descripcion', 'imagen', 'icono', 'duracion_min', 'precio', 'activo'];

    protected $casts = [
        'duracion_min' => 'integer',
        'precio' => 'decimal:2',
        'activo' => 'boolean',
    ];

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    /** Sucursales donde se ofrece. Vacío = disponible en todas las sucursales de la empresa. */
    public function bodegas(): BelongsToMany
    {
        return $this->belongsToMany(Bodega::class, 'bodega_servicio');
    }

    /** Galería de fotos de referencia (ej. cortes de cabello en una barbería). */
    public function galeria(): MorphMany
    {
        return $this->morphMany(GaleriaImagen::class, 'imageable')->orderBy('orden');
    }
}
