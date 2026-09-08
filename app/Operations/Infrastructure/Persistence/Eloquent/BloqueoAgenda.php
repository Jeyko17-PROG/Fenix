<?php

namespace App\Operations\Infrastructure\Persistence\Eloquent;

use App\Business\Infrastructure\Persistence\Eloquent\Bodega;
use App\Shared\Infrastructure\Persistence\Concerns\PerteneceAUsuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BloqueoAgenda extends Model
{
    use PerteneceAUsuario;

    protected $table = 'bloqueos_agenda';

    protected $fillable = ['owner_id', 'bodega_id', 'inicio', 'fin', 'motivo'];

    protected $casts = [
        'inicio' => 'datetime',
        'fin' => 'datetime',
    ];

    /** Sucursal a la que aplica. Nulo = bloqueo general de toda la empresa. */
    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class, 'bodega_id');
    }
}
