<?php

namespace App\Models\Concerns;

use App\Models\Empresa;
use App\Models\Scopes\OwnerScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Hace que un modelo pertenezca a una empresa (multi-tenant):
 *  - aplica el filtro global OwnerScope (cada empresa ve solo lo suyo),
 *  - asigna empresa_id automáticamente al crear,
 *  - conserva owner_id (usuario dueño del workspace) como referencia de
 *    auditoría y compatibilidad con el esquema anterior.
 */
trait PerteneceAUsuario
{
    /** Laravel lo invoca por instancia: habilita asignación masiva de las columnas del tenant. */
    public function initializePerteneceAUsuario(): void
    {
        $this->mergeFillable(['owner_id', 'empresa_id']);
    }

    public static function bootPerteneceAUsuario(): void
    {
        static::addGlobalScope(new OwnerScope);

        static::creating(function ($model) {
            if (empty($model->owner_id) && Auth::check()) {
                $model->owner_id = Auth::user()->workspaceOwnerId();
            }
            // Multiempresa: cada registro nace atado a la empresa del usuario.
            if (empty($model->empresa_id) && Auth::check()) {
                $model->empresa_id = Auth::user()->empresaId();
            }
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }
}
