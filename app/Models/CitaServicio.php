<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Línea de servicio dentro de una cita (una cita puede tener varias, ej.
 * Uñas + Pestañas). `servicio_id` nulo = línea personalizada capturada en el
 * momento (nombre y precio manual, sin catálogo).
 */
class CitaServicio extends Model
{
    protected $table = 'cita_servicio';

    protected $fillable = ['cita_id', 'servicio_id', 'nombre_personalizado', 'precio_unitario', 'duracion_min'];

    protected $casts = [
        'precio_unitario' => 'decimal:2',
        'duracion_min' => 'integer',
    ];

    public function cita(): BelongsTo
    {
        return $this->belongsTo(Cita::class);
    }

    public function servicio(): BelongsTo
    {
        return $this->belongsTo(Servicio::class);
    }
}
