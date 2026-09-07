<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAUsuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceOrder extends Model
{
    use PerteneceAUsuario, SoftDeletes;

    protected $table = 'service_orders';

    protected $fillable = [
        'owner_id',
        'cliente_id',
        'asset_vehicle_id',
        'plan_lavado_id',
        'servicio_id',
        'operables_employee_id',
        'numero_orden',
        'estado',
        'descripcion_trabajo',
        'km_entrada',
        'nivel_gasolina',
        'accesorios',
        'checklist_entrada',
        'subtotal',
        'total',
        'total_comisiones',
        'factura_id',
        'fecha_recepcion',
        'fecha_entrega_estimada',
        'fecha_entrega_real',
        'requiere_pago_anticipo',
        'monto_anticipo',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'total' => 'decimal:2',
        'total_comisiones' => 'decimal:2',
        'monto_anticipo' => 'decimal:2',
        'requiere_pago_anticipo' => 'boolean',
        'fecha_recepcion' => 'datetime',
        'fecha_entrega_estimada' => 'datetime',
        'fecha_entrega_real' => 'datetime',
        'checklist_entrada' => 'array',
    ];

    protected $appends = ['estado_label'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function assetVehicle(): BelongsTo
    {
        return $this->belongsTo(AssetVehicle::class, 'asset_vehicle_id');
    }

    public function planLavado(): BelongsTo
    {
        return $this->belongsTo(PlanLavado::class, 'plan_lavado_id');
    }

    public function servicio(): BelongsTo
    {
        return $this->belongsTo(Servicio::class, 'servicio_id');
    }

    /** Mecánico/técnico responsable de toda la orden. */
    public function mecanicoAsignado(): BelongsTo
    {
        return $this->belongsTo(OperablesEmployee::class, 'operables_employee_id');
    }

    public function factura(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Factura::class, 'factura_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(ServiceOrderDetail::class, 'service_order_id');
    }

    public function assetHistory(): HasMany
    {
        return $this->hasMany(AssetHistory::class, 'service_order_id');
    }

    /**
     * Etiqueta legible del estado.
     */
    public function getEstadoLabelAttribute(): string
    {
        return collect([
            'recibido' => 'Recibido',
            'en_proceso' => 'En Proceso',
            'secando' => 'Secando',
            'listo' => 'Listo',
            'facturado' => 'Facturado',
            'cancelado' => 'Cancelado',
        ])->get($this->estado, $this->estado ?? 'Recibido');
    }

    /**
     * Calcula automáticamente subtotal y comisiones.
     */
    public function recalculateTotals(): void
    {
        $this->subtotal = $this->details()->sum('subtotal');
        $this->total_comisiones = $this->details()->sum('comision_aplicada');
        $this->total = $this->subtotal;
        $this->save();
    }

    /**
     * Genera un número de orden único por inquilino.
     */
    public static function generarNumeroOrden(int $ownerId): string
    {
        $count = self::where('owner_id', $ownerId)->count() + 1;
        return "SO-" . date('Ymd') . "-" . str_pad($count, 5, '0', STR_PAD_LEFT);
    }
}
