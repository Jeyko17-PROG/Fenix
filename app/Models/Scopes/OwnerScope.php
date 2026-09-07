<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Filtro global multi-tenant por EMPRESA.
 *
 * Para cualquier usuario autenticado que NO sea super-admin, limita las
 * consultas a los registros de su empresa (empresa_id). Si el usuario aún
 * no tiene empresa asignada (datos previos al backfill), cae al filtro
 * anterior por owner_id para no dejarlo sin datos.
 *
 * El super-admin de la plataforma ve todo. Sin usuario autenticado
 * (CLI, portal público), no filtra.
 *
 * Nota: conserva el nombre OwnerScope porque el portal público y
 * AgendaService usan withoutGlobalScope(OwnerScope::class).
 */
class OwnerScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if (! $user || $user->esSuperAdmin()) {
            return;
        }

        $empresaId = $user->empresaId();
        if ($empresaId) {
            $builder->where($model->getTable() . '.empresa_id', $empresaId);
        } else {
            // Retrocompatibilidad: usuario sin empresa asignada todavía.
            $builder->where($model->getTable() . '.owner_id', $user->workspaceOwnerId());
        }
    }
}
