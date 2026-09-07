<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceOrderDetail extends Model
{
    protected $table = 'service_order_details';

    protected $fillable = [
        'service_order_id',
        'producto_id',
        'operables_employee_id',
        'cantidad',
        'precio_unitario',
        'subtotal',
        'tiene_comision',
        'tipo_comision',
        'comision_value',
        'comision_aplicada',
        'notas',
    ];

    protected $casts = [
        'cantidad' => 'integer',
        'precio_unitario' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'tiene_comision' => 'boolean',
        'comision_value' => 'decimal:2',
        'comision_aplicada' => 'decimal:2',
    ];

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class, 'service_order_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function operablesEmployee(): BelongsTo
    {
        return $this->belongsTo(OperablesEmployee::class, 'operables_employee_id');
    }

    /**
     * Calcula la comisión basada en el tipo y valor.
     * Si no hay empleado asignado, no aplica comisión.
     */
    public function calcularComision(): void
    {
        if (!$this->operables_employee_id || !$this->tiene_comision) {
            $this->comision_aplicada = 0;
            return;
        }

        $tipo = $this->tipo_comision ?? $this->operablesEmployee?->tipo_comision_default;
        $valor = $this->comision_value ?? $this->operablesEmployee?->comision_default;

        if (!$tipo || !$valor) {
            $this->comision_aplicada = 0;
            return;
        }

        if ($tipo === 'percentage') {
            $this->comision_aplicada = ($this->subtotal * $valor) / 100;
        } else {
            $this->comision_aplicada = $valor;
        }
    }
}
