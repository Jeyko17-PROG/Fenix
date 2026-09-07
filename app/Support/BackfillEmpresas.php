<?php

namespace App\Support;

use App\Models\Empresa;
use App\Models\EmpresaModulo;
use App\Models\Modulo;
use App\Models\TipoNegocio;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill del modelo multiempresa a partir del esquema anterior (owner_id):
 *  1. Cada usuario dueño (sin workspace_owner_id) → una Empresa con sus campos SaaS.
 *  2. users.empresa_id y es_admin_empresa.
 *  3. owner_id → empresa_id en todas las tablas de negocio.
 *  4. user_funcionalidades → empresa_modulos.
 *  5. empresa_id en billetera/pagos según el dueño del workspace.
 *
 * 100% idempotente (firstOrCreate + WHERE empresa_id IS NULL): se puede correr
 * en cada deploy o manualmente con `php artisan logix:backfill-empresas`.
 */
class BackfillEmpresas
{
    /** @return array<string,int> resumen de lo procesado */
    public static function run(): array
    {
        return DB::transaction(function () {
            $resumen = ['empresas_creadas' => 0, 'usuarios_vinculados' => 0, 'filas_migradas' => 0];

            // Tipo de negocio de respaldo para empresas existentes.
            $tipoOtro = TipoNegocio::firstOrCreate(
                ['clave' => 'otro'],
                ['nombre' => 'Otro negocio', 'orden' => 99, 'modulos_default' => null]
            );

            // 1. Dueños → empresas (incluye soft-deleted para no dejar huérfanos).
            $duenos = User::withTrashed()->whereNull('workspace_owner_id')->get();
            foreach ($duenos as $u) {
                $empresa = Empresa::withTrashed()->firstOrCreate(
                    ['owner_user_id' => $u->id],
                    [
                        'nombre' => $u->name,
                        'tipo_documento' => $u->tipo_documento,
                        'numero_documento' => $u->numero_documento,
                        'telefono' => $u->telefono,
                        'email' => $u->email,
                        'tipo_negocio_id' => $tipoOtro->id,
                        'plan_id' => $u->plan_id,
                        'modo_cobro' => $u->modo_cobro ?? 'membresia',
                        'membresia_vence_at' => $u->membresia_vence_at,
                        'estado' => $u->estado ?? 'ACTIVO',
                        'activo' => (bool) $u->activo,
                        'limite_clientes' => $u->limite_clientes,
                        'reservas_slug' => $u->reservas_slug,
                    ]
                );
                if ($empresa->wasRecentlyCreated) {
                    $resumen['empresas_creadas']++;
                }

                if ($u->empresa_id !== $empresa->id || ! $u->es_admin_empresa) {
                    $u->forceFill(['empresa_id' => $empresa->id, 'es_admin_empresa' => true])->saveQuietly();
                    $resumen['usuarios_vinculados']++;
                }
            }

            // 2. Empleados → empresa de su dueño. Huérfanos: empresa propia + log.
            $empleados = User::withTrashed()->whereNotNull('workspace_owner_id')->whereNull('empresa_id')->get();
            foreach ($empleados as $e) {
                $empresaId = Empresa::withTrashed()->where('owner_user_id', $e->workspace_owner_id)->value('id');
                if (! $empresaId) {
                    Log::warning('Backfill: empleado con workspace huérfano; se le crea empresa propia', ['user_id' => $e->id]);
                    $empresaId = Empresa::firstOrCreate(
                        ['owner_user_id' => $e->id],
                        ['nombre' => $e->name, 'tipo_negocio_id' => $tipoOtro->id, 'estado' => 'ACTIVO']
                    )->id;
                }
                $e->forceFill(['empresa_id' => $empresaId])->saveQuietly();
                $resumen['usuarios_vinculados']++;
            }

            // Mapa owner_user_id → empresa_id para las tablas de negocio.
            $mapa = Empresa::withTrashed()->pluck('id', 'owner_user_id');

            // 3. owner_id → empresa_id en las 23 tablas de negocio.
            $tablas = [
                'clientes', 'citas', 'facturas', 'notas', 'productos', 'movimientos_inventario',
                'proveedores', 'categorias', 'bodegas', 'servicios', 'ordenes_compra',
                'stock_por_bodega', 'documentos', 'horarios_laborales', 'bloqueos_agenda',
                'ajustes_agenda', 'adjuntos', 'operables_employees', 'assets_vehicles',
                'service_orders', 'commission_liquidations', 'caja_sesiones', 'gastos',
            ];
            foreach ($tablas as $tabla) {
                if (! Schema::hasTable($tabla) || ! Schema::hasColumn($tabla, 'empresa_id')) {
                    continue;
                }
                foreach ($mapa as $ownerId => $empresaId) {
                    $resumen['filas_migradas'] += DB::table($tabla)
                        ->where('owner_id', $ownerId)
                        ->whereNull('empresa_id')
                        ->update(['empresa_id' => $empresaId]);
                }
                $huerfanas = DB::table($tabla)->whereNotNull('owner_id')->whereNull('empresa_id')->count();
                if ($huerfanas > 0) {
                    Log::warning("Backfill: {$huerfanas} filas de {$tabla} con owner_id sin empresa (quedan NULL).");
                }
            }

            // 4. user_funcionalidades (overrides por usuario dueño) → empresa_modulos.
            if (Schema::hasTable('user_funcionalidades') && Schema::hasTable('empresa_modulos')) {
                $modulosPorClave = Modulo::pluck('id', 'clave');
                $overrides = DB::table('user_funcionalidades')->get();
                foreach ($overrides as $o) {
                    $empresaId = $mapa[$o->user_id] ?? null;
                    $moduloId = $modulosPorClave[$o->clave] ?? null;
                    if ($empresaId && $moduloId) {
                        EmpresaModulo::updateOrCreate(
                            ['empresa_id' => $empresaId, 'modulo_id' => $moduloId],
                            ['estado' => $o->estado]
                        );
                    }
                }
            }

            // 5. Billetera y pagos: empresa del dueño del workspace del user_id.
            $empresaDeUsuario = function (?int $userId) use ($mapa): ?int {
                if (! $userId) return null;
                if (isset($mapa[$userId])) return $mapa[$userId]; // es dueño
                $wsOwner = User::withTrashed()->where('id', $userId)->value('workspace_owner_id');
                return $wsOwner ? ($mapa[$wsOwner] ?? null) : null;
            };
            foreach (['user_credits', 'credit_transactions', 'payment_transactions', 'auditorias'] as $tabla) {
                if (! Schema::hasTable($tabla) || ! Schema::hasColumn($tabla, 'empresa_id')) {
                    continue;
                }
                $columnaUser = $tabla === 'auditorias' && ! Schema::hasColumn($tabla, 'user_id') ? null : 'user_id';
                if (! $columnaUser) continue;
                $userIds = DB::table($tabla)->whereNull('empresa_id')->whereNotNull($columnaUser)
                    ->distinct()->pluck($columnaUser);
                foreach ($userIds as $uid) {
                    if ($eid = $empresaDeUsuario((int) $uid)) {
                        DB::table($tabla)->where($columnaUser, $uid)->whereNull('empresa_id')
                            ->update(['empresa_id' => $eid]);
                    }
                }
            }

            return $resumen;
        });
    }
}
