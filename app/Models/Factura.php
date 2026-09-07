<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAUsuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Factura extends Model
{
    use PerteneceAUsuario, SoftDeletes;

    protected $table = 'facturas';

    protected $fillable = [
        'owner_id',
        'bodega_id',
        'numero', 'cliente_id', 'fecha', 'subtotal', 'impuestos', 'total',
        'estado', 'metodo_pago', 'propina', 'pdf_url', 'firma_url', 'notas', 'created_by',
        'currency', 'exchange_rate',
    ];

    protected $casts = [
        'fecha' => 'date',
        'subtotal' => 'decimal:2',
        'impuestos' => 'decimal:2',
        'total' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
    ];

    protected $appends = ['monto_pagado', 'saldo_pendiente'];

    /**
     * Siguiente número de factura de la EMPRESA (secuencia propia por negocio;
     * el unique en BD es compuesto: empresa_id + numero).
     */
    public static function siguienteNumero(?int $empresaId): string
    {
        $base = static::withTrashed()->withoutGlobalScopes();
        if ($empresaId) {
            $base->where('empresa_id', $empresaId);
        }

        $n = (clone $base)->count() + 1;
        do {
            $numero = 'FAC-' . str_pad((string) $n, 5, '0', STR_PAD_LEFT);
            $n++;
        } while ((clone $base)->where('numero', $numero)->exists());

        return $numero;
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(FacturaDetalle::class, 'factura_id');
    }

    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class, 'bodega_id');
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(FacturaPago::class, 'factura_id')->orderByDesc('fecha')->orderByDesc('id');
    }

    /**
     * Total abonado hasta ahora. Usa el resultado de withSum('pagos as pagos_sum_monto', ...)
     * si el listado ya lo precargó (evita una consulta aparte por cada factura,
     * igual patrón que Producto::getSalidasAttribute).
     */
    public function getMontoPagadoAttribute(): float
    {
        if (array_key_exists('pagos_sum_monto', $this->attributes)) {
            return (float) $this->attributes['pagos_sum_monto'];
        }
        if ($this->relationLoaded('pagos')) {
            return (float) $this->pagos->sum('monto');
        }
        return (float) $this->pagos()->sum('monto');
    }

    public function getSaldoPendienteAttribute(): float
    {
        return round((float) $this->total - $this->monto_pagado, 2);
    }
}
