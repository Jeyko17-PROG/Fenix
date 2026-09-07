<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CommissionLiquidation extends Model
{
    use SoftDeletes;

    protected $table = 'commission_liquidations';

    protected $fillable = [
        'owner_id',
        'operables_employee_id',
        'fecha_inicio',
        'fecha_fin',
        'monto_total',
        'estado',
        'fecha_pago',
        'referencia_pago',
        'notas',
    ];

    protected $casts = [
        'monto_total' => 'decimal:2',
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'fecha_pago' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function operablesEmployee(): BelongsTo
    {
        return $this->belongsTo(OperablesEmployee::class, 'operables_employee_id');
    }

    /**
     * Marca la liquidación como pagada.
     */
    public function marcarPagada(string $referencia = null): void
    {
        $this->estado = 'pagada';
        $this->fecha_pago = now();
        $this->referencia_pago = $referencia;
        $this->save();
    }

    /**
     * Genera una nueva liquidación automáticamente desde comisiones no liquidadas.
     */
    public static function generarLiquidacion(
        int $ownerId,
        int $operableId,
        string $fechaInicio,
        string $fechaFin
    ): ?self {
        $total = ServiceOrderDetail::whereHas('serviceOrder', fn ($q) => $q->where('owner_id', $ownerId))
            ->where('operables_employee_id', $operableId)
            ->whereBetween('created_at', [$fechaInicio, $fechaFin])
            ->sum('comision_aplicada');

        if ($total <= 0) {
            return null;
        }

        return self::create([
            'owner_id' => $ownerId,
            'operables_employee_id' => $operableId,
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'monto_total' => $total,
            'estado' => 'pendiente',
        ]);
    }
}
