<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAUsuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Mesa del restaurante (plano de mesas): LIBRE | OCUPADA | RESERVADA. */
class Mesa extends Model
{
    use PerteneceAUsuario;

    protected $table = 'mesas';

    protected $fillable = ['owner_id', 'empresa_id', 'nombre', 'estado', 'capacidad', 'orden'];

    public function comandas(): HasMany
    {
        return $this->hasMany(Comanda::class, 'mesa_id');
    }

    /** La comanda abierta actual de la mesa (si está ocupada). */
    public function comandaAbierta(): HasOne
    {
        return $this->hasOne(Comanda::class, 'mesa_id')->where('estado', 'ABIERTA')->latest();
    }
}
