<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Override de estado de un módulo para una empresa (asignado por el super-admin).
 * Estados: ACTIVADA | RESTRINGIDA (solo lectura) | DESACTIVADA.
 */
class EmpresaModulo extends Model
{
    protected $table = 'empresa_modulos';

    protected $fillable = ['empresa_id', 'modulo_id', 'estado'];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function modulo(): BelongsTo
    {
        return $this->belongsTo(Modulo::class, 'modulo_id');
    }
}
