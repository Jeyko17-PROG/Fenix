<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssetHistory extends Model
{
    use SoftDeletes;

    protected $table = 'asset_history';

    protected $fillable = [
        'asset_vehicle_id',
        'service_order_id',
        'descripcion_trabajo',
        'costo_total',
        'estado_entrada',
        'estado_salida',
        'km_entrada',
        'km_salida',
        'fecha_entrada',
        'fecha_salida',
    ];

    protected $casts = [
        'costo_total' => 'decimal:2',
        'km_entrada' => 'integer',
        'km_salida' => 'integer',
        'fecha_entrada' => 'datetime',
        'fecha_salida' => 'datetime',
    ];

    public function assetVehicle(): BelongsTo
    {
        return $this->belongsTo(AssetVehicle::class, 'asset_vehicle_id');
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class, 'service_order_id');
    }

    /**
     * Calcula los km recorridos durante el servicio.
     */
    public function getKmRecorridosAttribute(): ?int
    {
        if ($this->km_entrada && $this->km_salida) {
            return $this->km_salida - $this->km_entrada;
        }
        return null;
    }
}
