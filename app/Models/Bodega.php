<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAUsuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Bodega: además de ubicación de inventario, es la "sucursal" física del negocio (multisucursal). */
class Bodega extends Model
{
    use PerteneceAUsuario, SoftDeletes;

    protected $table = 'bodegas';

    protected $fillable = [
        'owner_id',
        'nombre',
        'direccion',
        'telefono',
        'ciudad',
        'responsable_id',
        'activo',
        'es_principal',
    ];

    protected $casts = ['activo' => 'boolean', 'es_principal' => 'boolean'];

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(StockBodega::class, 'bodega_id');
    }

    /** Servicios que ofrece esta sucursal. Vacío = el servicio no está limitado por sucursal. */
    public function servicios(): BelongsToMany
    {
        return $this->belongsToMany(Servicio::class, 'bodega_servicio');
    }

    public function citas(): HasMany
    {
        return $this->hasMany(Cita::class, 'bodega_id');
    }
}
