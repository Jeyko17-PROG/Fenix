<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAUsuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class OperablesEmployee extends Model
{
    use PerteneceAUsuario, SoftDeletes;

    protected $table = 'operables_employees';

    protected $fillable = [
        'owner_id',
        'user_id',
        'nombre',
        'apellido',
        'email',
        'telefono',
        'ci_cedula',
        'tipo_operario',
        'especialidad',
        'comision_default',
        'tipo_comision_default',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'comision_default' => 'decimal:2',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** Cuenta de acceso (login) vinculada a este empleado, si la tiene. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function serviceOrderDetails(): HasMany
    {
        return $this->hasMany(ServiceOrderDetail::class, 'operables_employee_id');
    }

    public function commissionLiquidations(): HasMany
    {
        return $this->hasMany(CommissionLiquidation::class, 'operables_employee_id');
    }

    /** Portafolio de fotos del artista (ej. trabajos previos de un tatuador). */
    public function galeria(): MorphMany
    {
        return $this->morphMany(GaleriaImagen::class, 'imageable')->orderBy('orden');
    }

    /**
     * Nombre completo del empleado.
     */
    public function getNombreCompletoAttribute(): string
    {
        return "{$this->nombre} {$this->apellido}";
    }

    /**
     * Total de comisiones cobradas en un rango de fechas.
     */
    public function getTotalComisionesEnRango($fechaInicio, $fechaFin): float
    {
        return (float) $this->serviceOrderDetails()
            ->whereBetween('created_at', [$fechaInicio, $fechaFin])
            ->whereNotNull('comision_aplicada')
            ->sum('comision_aplicada');
    }
}
