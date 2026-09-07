<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAUsuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cita extends Model
{
    use PerteneceAUsuario, SoftDeletes;

    protected $table = 'citas';

    protected $appends = ['icono_vehiculo'];

    protected $fillable = [
        'owner_id',
        'cliente_id', 'servicio_id', 'empleado_id', 'operables_employee_id', 'bodega_id',
        'tipo_vehiculo', 'placa', 'zona_cuerpo', 'tamano_tatuaje', 'plan_lavado_id',
        'inicio', 'fin', 'estado', 'observaciones', 'origen', 'created_by',
        // Foto de referencia elegida por el cliente al reservar (ej. corte de cabello deseado).
        'imagen_referencia_url',
    ];

    protected $casts = [
        'inicio' => 'datetime',
        'fin' => 'datetime',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function servicio(): BelongsTo
    {
        return $this->belongsTo(Servicio::class, 'servicio_id');
    }

    /**
     * Detalle de servicios de la cita (uno o varios, ej. Uñas + Pestañas).
     * `servicio_id` (columna directa, arriba) queda con el primero para
     * retrocompatibilidad; el desglose completo con precio/duración vive aquí.
     */
    public function detalleServicios(): HasMany
    {
        return $this->hasMany(CitaServicio::class);
    }

    public function planLavado(): BelongsTo
    {
        return $this->belongsTo(PlanLavado::class, 'plan_lavado_id');
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(User::class, 'empleado_id');
    }

    /**
     * Especialista (barbero/estilista/esteticien) elegido por el cliente en
     * el portal público. Roster operativo (comisiones), no requiere login.
     */
    public function operablesEmployee(): BelongsTo
    {
        return $this->belongsTo(OperablesEmployee::class, 'operables_employee_id');
    }

    /** Sucursal (bodega) donde se agendó la cita. Nulo = negocio de una sola sede. */
    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class, 'bodega_id');
    }

    /** Ícono del tipo de vehículo, para pintar en agenda/portal/QR (🏍️ moto, 🚗 carro). */
    public function getIconoVehiculoAttribute(): ?string
    {
        return match ($this->tipo_vehiculo) {
            'moto' => '🏍️',
            'carro' => '🚗',
            default => null,
        };
    }

    /** Estados que ocupan un horario (no liberan el slot). */
    public const ESTADOS_ACTIVOS = ['PENDIENTE', 'CONFIRMADA', 'REPROGRAMADA', 'COMPLETADA'];
}
