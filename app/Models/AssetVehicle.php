<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAUsuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssetVehicle extends Model
{
    use PerteneceAUsuario, SoftDeletes;

    protected $table = 'assets_vehicles';

    protected $fillable = [
        'owner_id',
        'cliente_id',
        'tipo_activo',
        'placa_identificador',
        'marca',
        'modelo',
        'anio',
        'color',
        'descripcion',
        'notas_tecnicas',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'anio' => 'integer',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function serviceOrders(): HasMany
    {
        return $this->hasMany(ServiceOrder::class, 'asset_vehicle_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(AssetHistory::class, 'asset_vehicle_id')->orderByDesc('fecha_entrada');
    }

    /**
     * Retorna el identificador para mostrar (placa, IMEI, etc).
     */
    public function getIdentificadorAttribute(): string
    {
        return $this->placa_identificador ?? "SIN_{$this->tipo_activo}_{$this->id}";
    }

    /**
     * Hoja de vida completa del activo.
     */
    public function getHojaDeVidaAttribute(): array
    {
        return $this->history()
            ->with('serviceOrder')
            ->get()
            ->map(fn ($h) => [
                'fecha' => $h->fecha_entrada,
                'trabajo' => $h->descripcion_trabajo,
                'costo' => $h->costo_total,
                'estado_entrada' => $h->estado_entrada,
                'estado_salida' => $h->estado_salida,
                'km_entrada' => $h->km_entrada,
                'km_salida' => $h->km_salida,
            ])
            ->toArray();
    }
}
