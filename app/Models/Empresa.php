<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Empresa (tenant del SaaS): dueña de todos los datos de negocio.
 * Los campos de cobro (plan, membresía, billetera) viven aquí; el modelo
 * User conserva fachadas retrocompatibles que delegan en esta clase.
 */
class Empresa extends Model
{
    use SoftDeletes;

    protected $table = 'empresas';

    protected $fillable = [
        'nombre',
        'tipo_documento',
        'numero_documento',
        'telefono',
        'email',
        'email_facturacion',
        'direccion',
        'logo_url',
        'logo_emoji',
        'politicas',
        'instagram_url',
        'tiktok_url',
        'facebook_url',
        'whatsapp_url',
        'tipo_negocio_id',
        'owner_user_id',
        'plan_id',
        'modo_cobro',
        'membresia_vence_at',
        'prueba_alerta_enviada',
        'estado',
        'activo',
        'limite_clientes',
        'limite_citas',
        'reservas_slug',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'membresia_vence_at' => 'datetime',
        'prueba_alerta_enviada' => 'boolean',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function tipoNegocio(): BelongsTo
    {
        return $this->belongsTo(TipoNegocio::class, 'tipo_negocio_id');
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class, 'empresa_id');
    }

    /** Overrides de módulos definidos por el super-admin para esta empresa. */
    public function modulos(): HasMany
    {
        return $this->hasMany(EmpresaModulo::class, 'empresa_id');
    }

    /**
     * IDs de todas las empresas del mismo "grupo de facturación": esta +
     * las de los negocios que su dueño tenga vinculados en "Mis negocios".
     * Un negocio sin vínculos es un grupo de 1 (su propio id) — comportamiento
     * de siempre, sin cambios para la enorme mayoría de las cuentas.
     */
    public function grupoEmpresaIds(): array
    {
        return $this->owner ? $this->owner->grupoEmpresaIds() : [$this->id];
    }

    /**
     * La empresa que "gobierna" el plan/membresía de todo el grupo: la más
     * antigua (decisión de negocio — el plan del negocio más antiguo cubre
     * a los que se vinculen después). Sin vínculos, es ella misma.
     */
    public function empresaGobernante(): self
    {
        $ids = $this->grupoEmpresaIds();
        if (count($ids) <= 1) {
            return $this;
        }
        return static::withTrashed()->whereIn('id', $ids)->orderBy('created_at')->first() ?? $this;
    }

    /**
     * ¿La membresía (o la prueba gratuita) está vencida? Ambos modos comparten
     * el mismo campo membresia_vence_at: en 'prueba' se usa como fecha límite
     * de los 15 días gratis; al pagar, renovarMembresia() cambia el modo a
     * 'membresia' y la empresa queda igual que cualquier cliente pago.
     * Se evalúa sobre la empresa gobernante: un solo plan cubre a todo el
     * grupo de negocios vinculados, así que basta con que ESE pago esté al día.
     */
    public function membresiaVencida(): bool
    {
        $g = $this->empresaGobernante();
        return in_array($g->modo_cobro, ['membresia', 'prueba'], true)
            && $g->membresia_vence_at !== null
            && $g->membresia_vence_at->isPast();
    }

    /**
     * Renueva la membresía N meses. Siempre actualiza la empresa GOBERNANTE
     * del grupo (la que realmente sostiene el plan pagado), aunque se haya
     * llamado sobre otro negocio vinculado — así el pago beneficia a todos.
     */
    public function renovarMembresia(int $meses = 1): void
    {
        $g = $this->empresaGobernante();
        $base = ($g->membresia_vence_at && $g->membresia_vence_at->isFuture())
            ? $g->membresia_vence_at
            : now();

        $g->forceFill([
            'membresia_vence_at' => $base->copy()->addMonths($meses),
            'modo_cobro' => 'membresia',
            'estado' => 'ACTIVO',
            'activo' => true,
        ])->save();

        if ($g->isNot($this)) {
            $this->setRawAttributes($g->getAttributes()); // refleja el cambio también en $this
        }
    }

    /** Plan real que aplica: el de la empresa gobernante del grupo (o el propio, sin vínculos). */
    public function planEfectivo(): ?Plan
    {
        return $this->empresaGobernante()->plan;
    }

    /** Límite efectivo de clientes: override manual o el del plan, de la empresa gobernante del grupo. */
    public function limiteClientesEfectivo(): int
    {
        $g = $this->empresaGobernante();
        if (! is_null($g->limite_clientes)) {
            return (int) $g->limite_clientes;
        }
        return (int) ($g->plan?->limite_clientes ?? 0);
    }

    /**
     * Clientes registrados por TODO el grupo de negocios vinculados (no solo
     * esta empresa): el cupo del plan se comparte, así que el consumo
     * también se cuenta junto. Sin vínculos, es el mismo conteo de siempre.
     */
    public function clientesUsados(): int
    {
        return Cliente::withoutGlobalScopes()->whereIn('empresa_id', $this->grupoEmpresaIds())->count();
    }

    /** Límite efectivo de citas: override manual o el del plan, de la empresa gobernante del grupo. */
    public function limiteCitasEfectivo(): int
    {
        $g = $this->empresaGobernante();
        if (! is_null($g->limite_citas)) {
            return (int) $g->limite_citas;
        }
        return (int) ($g->plan?->limite_citas ?? 0);
    }

    /** Citas registradas por TODO el grupo de negocios vinculados (mismo criterio que clientesUsados()). */
    public function citasUsadas(): int
    {
        return Cita::withoutGlobalScopes()->whereIn('empresa_id', $this->grupoEmpresaIds())->count();
    }

    /** Genera (si falta) el slug público único del portal de reservas. */
    public function generarReservasSlug(): string
    {
        if ($this->reservas_slug) {
            return $this->reservas_slug;
        }

        $base = \Illuminate\Support\Str::slug($this->nombre) ?: 'negocio';
        $slug = $base;
        if (static::where('reservas_slug', $slug)->where('id', '!=', $this->id)->exists()) {
            $slug = $base . '-' . $this->id;
        }

        $this->forceFill(['reservas_slug' => $slug])->save();
        return $slug;
    }
}
