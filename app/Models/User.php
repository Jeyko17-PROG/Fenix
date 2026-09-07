<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use App\Notifications\RestablecerPasswordNotification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'tipo_documento',
        'numero_documento',
        'reservas_slug',
        'email',
        'password',
        'rol_id',
        'plan_id',
        'modo_cobro',
        'membresia_vence_at',
        'limite_clientes',
        'limite_citas',
        'foto_perfil_url',
        'telefono',
        'activo',
        'estado',
        'codigo_activacion',
        'codigo_activacion_intentos',
        'veces_login',
        'es_super_admin',
        'es_admin_empresa',
        'workspace_owner_id',
        'empresa_id',
        'bodega_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Relaciones
     */
    public function credits()
    {
        return $this->hasMany(\App\Models\UserCredit::class, 'user_id');
    }

    protected $casts = [
        'email_verified_at' => 'datetime',
        'ultimo_acceso' => 'datetime',
        'password' => 'hashed',
        'activo' => 'boolean',
        'es_super_admin' => 'boolean',
        'es_admin_empresa' => 'boolean',
        'membresia_vence_at' => 'datetime',
    ];

    /** El administrador principal que supervisa todas las cuentas. */
    public function esSuperAdmin(): bool
    {
        return (bool) $this->es_super_admin;
    }

    /**
     * Propietario/administrador de su empresa: acceso total a los datos de SU empresa.
     * (es_admin_empresa; los roles "Administrador"/"Usuario" se mantienen como respaldo.)
     */
    public function esPropietario(): bool
    {
        return $this->esSuperAdmin()
            || (bool) $this->es_admin_empresa
            || $this->tieneRol('Administrador', 'Usuario');
    }

    /** Dueño operativo del workspace: el usuario mismo o el administrador que creó al empleado. */
    public function workspaceOwnerId(): int
    {
        return (int) ($this->workspace_owner_id ?: $this->id);
    }

    /** Empresa (tenant) a la que pertenece el usuario. */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    /** Id de la empresa del usuario, con respaldo vía el dueño del workspace. */
    public function empresaId(): ?int
    {
        if ($this->empresa_id) {
            return (int) $this->empresa_id;
        }
        return $this->workspaceOwner?->empresa_id ? (int) $this->workspaceOwner->empresa_id : null;
    }

    /**
     * IDs de las empresas del "grupo de facturación" de este usuario: la
     * suya propia + las de los negocios que tenga vinculados ("Mis
     * negocios"). El plan se comparte entre todo el grupo (decisión de
     * negocio), pero el aislamiento de DATOS (empresaId/OwnerScope) no
     * cambia: cada negocio sigue siendo dueño exclusivo de sus propios
     * clientes, citas, productos, etc.
     */
    public function grupoEmpresaIds(): array
    {
        $ids = collect([$this->empresaId()])->filter();
        $vinculadosIds = $this->negociosVinculadosIds();
        if ($vinculadosIds->isNotEmpty()) {
            $otras = static::whereIn('id', $vinculadosIds)->pluck('empresa_id')->filter();
            $ids = $ids->merge($otras);
        }
        return $ids->unique()->values()->all();
    }

    /** Rol Mecánico/Técnico: solo ve sus órdenes asignadas, sin acceso a facturación ni precios. */
    public function esMecanico(): bool
    {
        return $this->tieneRol('Mecanico');
    }

    /** Rol Lavador (operario del lavadero): solo ve las citas que tiene asignadas. */
    public function esLavador(): bool
    {
        return $this->tieneRol('Lavador');
    }

    /** Rol Instalador (accesorios de motos): solo ve sus órdenes asignadas, sin acceso a facturación ni precios. */
    public function esInstalador(): bool
    {
        return $this->tieneRol('Instalador');
    }

    /**
     * FACHADA — Usuario responsable del cobro SaaS: el dueño de la empresa.
     * (Si aún no hay empresa, cae al dueño del workspace como antes.)
     */
    public function billingOwner(): User
    {
        if ($this->empresa && $this->empresa->owner) {
            return $this->empresa->owner;
        }
        if ($this->workspace_owner_id && $this->workspaceOwner) {
            return $this->workspaceOwner;
        }
        return $this;
    }

    /**
     * Empresa del usuario (resuelta también para empleados). Es SIEMPRE la
     * propia — identidad, nombre, tipo de negocio, logo y estado operativo
     * nunca deben "saltar" a otro negocio vinculado. Para lo que sí se
     * comparte entre negocios vinculados (plan, membresía, cupos), los
     * métodos de Empresa (limiteClientesEfectivo, membresiaVencida,
     * clientesUsados, etc.) ya resuelven internamente la empresa gobernante
     * del grupo vía Empresa::empresaGobernante() — no hace falta redirigir
     * esta fachada. Donde de verdad se necesite la gobernante explícitamente
     * (ej. escribir el plan), se llama `->empresaGobernante()` aparte.
     */
    public function empresaDeCobro(): ?Empresa
    {
        $id = $this->empresaId();
        return $id ? Empresa::withTrashed()->find($id) : null;
    }

    /**
     * FACHADA — ¿La membresía mensual está vencida? La verdad vive en la empresa;
     * si el usuario no tiene empresa aún, se usan los campos antiguos de users.
     */
    public function membresiaVencida(): bool
    {
        if ($empresa = $this->empresaDeCobro()) {
            return $empresa->membresiaVencida();
        }
        return $this->modo_cobro === 'membresia'
            && $this->membresia_vence_at !== null
            && $this->membresia_vence_at->isPast();
    }

    /**
     * FACHADA — Renueva la membresía de la EMPRESA y sincroniza los campos
     * antiguos del usuario dueño durante la transición.
     */
    public function renovarMembresia(int $meses = 1): void
    {
        if ($empresa = $this->empresaDeCobro()) {
            $empresa->renovarMembresia($meses);
            // Sincroniza los campos legados del dueño (paneles/reportes antiguos).
            $empresa->owner?->forceFill([
                'membresia_vence_at' => $empresa->membresia_vence_at,
                'modo_cobro' => $empresa->modo_cobro,
                'estado' => 'ACTIVO',
                'activo' => true,
            ])->saveQuietly();
            $this->refresh();
            return;
        }

        $base = ($this->membresia_vence_at && $this->membresia_vence_at->isFuture())
            ? $this->membresia_vence_at
            : now();

        $this->forceFill([
            'membresia_vence_at' => $base->copy()->addMonths($meses),
            'modo_cobro' => 'membresia',
            'estado' => 'ACTIVO',
            'activo' => true,
        ])->save();
    }

    /**
     * Saca a la cuenta del estado "pendiente de activación" (por código
     * correcto en /activar o porque el super-admin la activó a mano desde el
     * panel) y arranca el reloj de los 15 días de prueba gratis si aún no
     * había arrancado. Centralizado aquí para que ningún camino de activación
     * se le olvide iniciar la prueba (dejaría la empresa sin fecha de
     * vencimiento y nunca se bloquearía).
     */
    public function activarPendiente(): void
    {
        $this->forceFill([
            'estado' => 'ACTIVO',
            'activo' => true,
            'codigo_activacion' => null,
            'codigo_activacion_intentos' => 0,
        ])->save();

        // La prueba gratis (o el plan pagado) se comparte entre negocios
        // vinculados: se revisa/arranca sobre la empresa GOBERNANTE del
        // grupo, no sobre esta empresa nueva (que no debe tener su propio
        // reloj de 15 días independiente si ya hay un grupo con plan activo).
        $empresa = $this->empresaDeCobro()?->empresaGobernante();
        if ($empresa && $empresa->modo_cobro === 'prueba' && ! $empresa->membresia_vence_at) {
            $empresa->forceFill(['membresia_vence_at' => now()->addDays(15)])->save();
        }
    }

    /** Un empleado queda limitado a la bodega/local asignado. */
    public function estaLimitadoABodega(): bool
    {
        return ! $this->esSuperAdmin()
            && ! $this->tieneRol('Administrador', 'Usuario')
            && ! empty($this->bodega_id);
    }

    /** Plan de suscripción del usuario. */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function workspaceOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'workspace_owner_id');
    }

    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class, 'bodega_id');
    }

    /**
     * Límite efectivo de clientes: override manual del usuario, o el del plan.
     * El super-admin no tiene límite (PHP_INT_MAX).
     */
    public function limiteClientesEfectivo(): int
    {
        if ($this->esSuperAdmin()) {
            return PHP_INT_MAX;
        }
        // FACHADA: el límite efectivo vive en la empresa (override o plan de la empresa).
        if ($empresa = $this->empresaDeCobro()) {
            return $empresa->limiteClientesEfectivo();
        }
        if (! is_null($this->limite_clientes)) {
            return (int) $this->limite_clientes;
        }
        return (int) ($this->plan?->limite_clientes ?? 0);
    }

    /** Plan efectivo: el de la empresa gobernante del grupo (o el del usuario si aún no tiene empresa). */
    public function planEfectivo(): ?Plan
    {
        return $this->empresaDeCobro()?->planEfectivo() ?? $this->plan;
    }

    /**
     * Cantidad de clientes registrados en la empresa del usuario (o en todo
     * su grupo de negocios vinculados, si tiene — ver Empresa::clientesUsados()).
     */
    public function clientesUsados(): int
    {
        if ($empresa = $this->empresaDeCobro()) {
            return $empresa->clientesUsados();
        }
        return Cliente::withoutGlobalScopes()->where('owner_id', $this->id)->count();
    }

    /**
     * FACHADA — Límite efectivo de citas: el de la empresa (override o plan),
     * con respaldo al override/plan propio del usuario si aún no tiene empresa.
     */
    public function limiteCitasEfectivo(): int
    {
        if ($this->esSuperAdmin()) {
            return PHP_INT_MAX;
        }
        if ($empresa = $this->empresaDeCobro()) {
            return $empresa->limiteCitasEfectivo();
        }
        if (! is_null($this->limite_citas)) {
            return (int) $this->limite_citas;
        }
        return (int) ($this->plan?->limite_citas ?? 0);
    }

    /** Citas registradas por el negocio (empresa si ya la tiene, si no por owner_id), o por todo el grupo vinculado. */
    public function citasUsadas(): int
    {
        if ($empresa = $this->empresaDeCobro()) {
            return $empresa->citasUsadas();
        }
        return Cita::withoutGlobalScopes()->where('owner_id', $this->id)->count();
    }

    /**
     * IDs de las otras cuentas (otros negocios) vinculadas a esta, en
     * cualquiera de los dos sentidos ("Mis negocios": una misma persona con
     * varios negocios/cuentas que puede alternar sin volver a iniciar sesión).
     */
    public function negociosVinculadosIds(): \Illuminate\Support\Collection
    {
        $comoOrigen = NegocioVinculado::where('user_id', $this->id)->pluck('vinculado_user_id');
        $comoDestino = NegocioVinculado::where('vinculado_user_id', $this->id)->pluck('user_id');
        return $comoOrigen->merge($comoDestino)->unique()->values();
    }

    /** Id del negocio principal (super-admin) para el portal público de reservas. */
    public static function negocioPrincipalId(): ?int
    {
        return static::where('es_super_admin', true)->value('id')
            ?? static::orderBy('id')->value('id');
    }

    /**
     * FACHADA — Genera (si falta) el slug público único del portal de reservas.
     * El slug canónico vive en la empresa; se mantiene copia en users por
     * compatibilidad con los enlaces/QR existentes.
     */
    public function generarReservasSlug(): string
    {
        if ($empresa = $this->empresaDeCobro()) {
            $slug = $empresa->reservas_slug ?: ($this->reservas_slug ?: $empresa->generarReservasSlug());
            if ($empresa->reservas_slug !== $slug) {
                $empresa->forceFill(['reservas_slug' => $slug])->saveQuietly();
            }
            if ($this->reservas_slug !== $slug) {
                $this->forceFill(['reservas_slug' => $slug])->saveQuietly();
            }
            return $slug;
        }

        if ($this->reservas_slug) {
            return $this->reservas_slug;
        }

        $base = \Illuminate\Support\Str::slug($this->name) ?: 'negocio';
        $slug = $base;
        if (static::where('reservas_slug', $slug)->where('id', '!=', $this->id)->exists()) {
            $slug = $base . '-' . $this->id;
        }

        $this->forceFill(['reservas_slug' => $slug])->save();
        return $slug;
    }

    /**
     * Rol al que pertenece el usuario (RBAC).
     */
    public function rol(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'rol_id');
    }

    /**
     * Indica si el usuario tiene alguno de los roles dados (por nombre).
     */
    public function tieneRol(string ...$nombres): bool
    {
        return $this->rol !== null && in_array($this->rol->nombre, $nombres, true);
    }

    /**
     * Envía el correo de recuperación con un enlace al frontend (SPA).
     */
    public function sendPasswordResetNotification($token): void
    {
        $frontend = rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/');
        $url = $frontend . '/restablecer?token=' . $token . '&email=' . urlencode($this->email);

        $this->notify(new RestablecerPasswordNotification($url));
    }
}
