<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAUsuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un abono/pago parcial hecho contra una factura. Una factura puede tener
 * varios pagos hasta cubrir el total (ej. venta a crédito pagada en cuotas).
 */
class FacturaPago extends Model
{
    use PerteneceAUsuario;

    protected $table = 'factura_pagos';

    protected $fillable = [
        'owner_id',
        'factura_id',
        'monto',
        'metodo_pago',
        'fecha',
        'nota',
        'created_by',
    ];

    protected $casts = [
        'monto' => 'decimal:2',
        'fecha' => 'date',
    ];

    public function factura(): BelongsTo
    {
        return $this->belongsTo(Factura::class, 'factura_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
