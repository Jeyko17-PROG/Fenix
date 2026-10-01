<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Migración ÚNICA de datos reales desde el sistema viejo (Logix, MySQL) hacia
 * Fenix (Postgres). Copia solo datos de negocio reales — los catálogos
 * (roles, permisos, plans, tipos_negocio, modulos, credit_packages) NO se
 * copian: Fenix ya los siembra con DatabaseSeeder y son la versión vigente.
 *
 * Usa la conexión de solo lectura `mysql_legacy` (config/database.php) y
 * escribe en la conexión por defecto (pgsql) vía query builder (no Eloquent)
 * para no disparar efectos secundarios (correos, numeración automática, etc.)
 * durante la copia — es una migración de datos, no una creación de negocio.
 *
 * Uso:
 *   php artisan migrar:logix --dry-run   (todo dentro de una transacción que
 *                                          se revierte al final — para probar)
 *   php artisan migrar:logix             (migración real, confirma los datos)
 */
class MigrarLogix extends Command
{
    protected $signature = 'migrar:logix {--dry-run : Ejecuta todo dentro de una transacción y la revierte al final}';
    protected $description = 'Copia los datos reales de producción desde Logix (MySQL) a Fenix (Postgres).';

    /** @var array<string, array<int,int>> old_id => new_id, por tabla */
    private array $map = [];

    public function handle(): int
    {
        $legacy = DB::connection('mysql_legacy');
        try {
            $legacy->getPdo();
        } catch (\Throwable $e) {
            $this->error('No se pudo conectar a mysql_legacy: ' . $e->getMessage());
            $this->error('Revisa LEGACY_DB_HOST/LEGACY_DB_DATABASE/LEGACY_DB_USERNAME/LEGACY_DB_PASSWORD en .env.');
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->info($dryRun ? '=== DRY RUN: se revertirá todo al final ===' : '=== MIGRACIÓN REAL ===');

        $resultado = self::SUCCESS;
        try {
            DB::transaction(function () use ($legacy, $dryRun) {
                $this->migrarRolesYPlanesComoCatalogo($legacy);
                $this->migrarUsuarios($legacy);
                $this->migrarEmpresas($legacy);
                $this->vincularUsuariosAEmpresas($legacy);
                $this->migrarEmpresaModulos($legacy);
                $this->migrarAjustesAgenda($legacy);
                $this->migrarBodegas($legacy);
                $this->migrarCategorias($legacy);
                $this->migrarProveedores($legacy);
                $this->migrarProductos($legacy);
                $this->migrarClientes($legacy);
                $this->migrarStockPorBodega($legacy);
                $this->migrarOperablesEmployees($legacy);
                $this->migrarFacturasYDetalle($legacy);
                $this->migrarFacturaPagos($legacy);
                $this->migrarMetodosPagoCobro($legacy);
                $this->migrarCajaSesiones($legacy);
                $this->migrarGastos($legacy);
                $this->migrarMovimientosInventario($legacy);
                $this->migrarHorariosLaborales($legacy);
                $this->migrarNotas($legacy);

                if ($dryRun) {
                    // Fuerza el rollback de todo lo anterior: no es un error real,
                    // se captura abajo como éxito del dry-run.
                    throw new \RuntimeException('__DRY_RUN_ROLLBACK__');
                }
            }, 1);
        } catch (\Throwable $e) {
            if ($dryRun && $e->getMessage() === '__DRY_RUN_ROLLBACK__') {
                $this->warn('Dry run: todo revertido (rollback intencional).');
            } else {
                $this->error('Falló la migración, se revirtió todo: ' . $e->getMessage());
                $this->line($e->getTraceAsString());
                $resultado = self::FAILURE;
            }
        }

        if ($resultado === self::SUCCESS) {
            $this->info($dryRun ? 'Dry run OK.' : 'Migración completada y confirmada.');
        }

        return $resultado;
    }

    // ---------------------------------------------------------------
    // Catálogos: solo construye mapas id-viejo -> id-nuevo por `nombre`/
    // `clave` (Fenix ya los sembró con DatabaseSeeder; no se insertan filas).
    // ---------------------------------------------------------------
    private function migrarRolesYPlanesComoCatalogo($legacy): void
    {
        $rolesViejos = $legacy->table('roles')->pluck('nombre', 'id');
        $rolesNuevos = DB::table('roles')->pluck('id', 'nombre');
        foreach ($rolesViejos as $oldId => $nombre) {
            if (isset($rolesNuevos[$nombre])) {
                $this->map['roles'][$oldId] = $rolesNuevos[$nombre];
            }
        }

        $planesViejos = $legacy->table('plans')->pluck('nombre', 'id');
        $planesNuevos = DB::table('plans')->pluck('id', 'nombre');
        foreach ($planesViejos as $oldId => $nombre) {
            if (isset($planesNuevos[$nombre])) {
                $this->map['plans'][$oldId] = $planesNuevos[$nombre];
            }
        }

        $tiposViejos = $legacy->table('tipos_negocio')->pluck('clave', 'id');
        $tiposNuevos = DB::table('tipos_negocio')->pluck('id', 'clave');
        foreach ($tiposViejos as $oldId => $clave) {
            if (isset($tiposNuevos[$clave])) {
                $this->map['tipos_negocio'][$oldId] = $tiposNuevos[$clave];
            }
        }

        $modulosViejos = $legacy->table('modulos')->pluck('clave', 'id');
        $modulosNuevos = DB::table('modulos')->pluck('id', 'clave');
        foreach ($modulosViejos as $oldId => $clave) {
            if (isset($modulosNuevos[$clave])) {
                $this->map['modulos'][$oldId] = $modulosNuevos[$clave];
            }
        }

        $this->info('Catálogos mapeados: ' . count($this->map['roles'] ?? []) . ' roles, '
            . count($this->map['plans'] ?? []) . ' planes, ' . count($this->map['tipos_negocio'] ?? []) . ' tipos de negocio, '
            . count($this->map['modulos'] ?? []) . ' módulos.');
    }

    private function migrarUsuarios($legacy): void
    {
        $filas = $legacy->table('users')->get();
        foreach ($filas as $row) {
            $r = (array) $row;

            // AdminUserSeeder (corre en cada arranque del contenedor) ya crea
            // algunas de estas cuentas por email (luisgarciab193@gmail.com,
            // admin@logix.test) — actualiza esa fila con los datos reales de
            // Logix en vez de intentar duplicarla.
            $existenteId = DB::table('users')->where('email', $r['email'])->value('id');

            $datos = [
                'rol_id' => $this->map['roles'][$r['rol_id']] ?? null,
                'plan_id' => $this->map['plans'][$r['plan_id']] ?? null,
                'modo_cobro' => $r['modo_cobro'] ?? 'membresia',
                'membresia_vence_at' => $r['membresia_vence_at'] ?? null,
                'limite_clientes' => $r['limite_clientes'] ?? null,
                'limite_citas' => $r['limite_citas'] ?? null,
                'limite_facturas' => null, // columna nueva en Fenix: usa el límite del plan.
                'name' => $r['name'],
                'tipo_documento' => $r['tipo_documento'] ?? null,
                'numero_documento' => $r['numero_documento'] ?? null,
                'reservas_slug' => $r['reservas_slug'] ?? null,
                'email' => $r['email'],
                'foto_perfil_url' => $r['foto_perfil_url'] ?? null,
                'telefono' => $r['telefono'] ?? null,
                'activo' => (bool) ($r['activo'] ?? true),
                'estado' => $r['estado'] ?? 'ACTIVO',
                'codigo_activacion' => $r['codigo_activacion'] ?? null,
                'codigo_activacion_intentos' => $r['codigo_activacion_intentos'] ?? 0,
                'ultimo_acceso' => $r['ultimo_acceso'] ?? null,
                'veces_login' => $r['veces_login'] ?? 0,
                'es_super_admin' => (bool) ($r['es_super_admin'] ?? false),
                'es_admin_empresa' => (bool) ($r['es_admin_empresa'] ?? false),
                // workspace_owner_id/empresa_id/bodega_id se completan en vincularUsuariosAEmpresas()
                // una vez existan las empresas/bodegas nuevas (dependencia circular).
                'workspace_owner_id' => null,
                'empresa_id' => null,
                'bodega_id' => null,
                'email_verified_at' => $r['email_verified_at'] ?? null,
                'password' => $r['password'],
                'remember_token' => $r['remember_token'] ?? null,
                'created_at' => $r['created_at'] ?? now(),
                'updated_at' => $r['updated_at'] ?? now(),
                'deleted_at' => $r['deleted_at'] ?? null,
            ];

            if ($existenteId) {
                DB::table('users')->where('id', $existenteId)->update($datos);
                $newId = $existenteId;
            } else {
                $newId = DB::table('users')->insertGetId($datos);
            }
            $this->map['users'][$r['id']] = $newId;
        }
        $this->info('Usuarios migrados: ' . count($filas));
    }

    private function migrarEmpresas($legacy): void
    {
        $filas = $legacy->table('empresas')->get();
        foreach ($filas as $row) {
            $r = (array) $row;
            $ownerNuevo = $this->map['users'][$r['owner_user_id']] ?? null;
            if (! $ownerNuevo) {
                $this->warn("Empresa vieja id={$r['id']}: owner_user_id={$r['owner_user_id']} no tiene usuario migrado, se omite.");
                continue;
            }
            // BackfillEmpresas (disparado por AdminUserSeeder en cada arranque)
            // ya pudo haber creado una empresa placeholder ("Otro negocio")
            // para este mismo owner_user_id — actualízala con los datos reales.
            $existenteId = DB::table('empresas')->where('owner_user_id', $ownerNuevo)->value('id');

            $datos = [
                'nombre' => $r['nombre'],
                'tipo_documento' => $r['tipo_documento'] ?? null,
                'numero_documento' => $r['numero_documento'] ?? null,
                'telefono' => $r['telefono'] ?? null,
                'email' => $r['email'] ?? null,
                'email_facturacion' => $r['email_facturacion'] ?? null,
                'direccion' => $r['direccion'] ?? null,
                'politicas' => $r['politicas'] ?? null,
                'instagram_url' => $r['instagram_url'] ?? null,
                'tiktok_url' => $r['tiktok_url'] ?? null,
                'facebook_url' => $r['facebook_url'] ?? null,
                'whatsapp_url' => $r['whatsapp_url'] ?? null,
                'logo_url' => $r['logo_url'] ?? null,
                'logo_emoji' => $r['logo_emoji'] ?? null,
                'tipo_negocio_id' => $this->map['tipos_negocio'][$r['tipo_negocio_id']] ?? null,
                'tipo_negocio_otro' => $r['tipo_negocio_otro'] ?? null,
                'owner_user_id' => $ownerNuevo,
                'plan_id' => $this->map['plans'][$r['plan_id']] ?? null,
                'limite_clientes' => $r['limite_clientes'] ?? null,
                'limite_citas' => $r['limite_citas'] ?? null,
                'limite_facturas' => null,
                'modo_cobro' => $r['modo_cobro'] ?? 'membresia',
                'membresia_vence_at' => $r['membresia_vence_at'] ?? null,
                'prueba_alerta_enviada' => (bool) ($r['prueba_alerta_enviada'] ?? false),
                'estado' => $r['estado'] ?? 'ACTIVA',
                'activo' => (bool) ($r['activo'] ?? true),
                'reservas_slug' => $r['reservas_slug'] ?? null,
                'created_at' => $r['created_at'] ?? now(),
                'updated_at' => $r['updated_at'] ?? now(),
                'deleted_at' => $r['deleted_at'] ?? null,
            ];

            if ($existenteId) {
                DB::table('empresas')->where('id', $existenteId)->update($datos);
                $newId = $existenteId;
            } else {
                $newId = DB::table('empresas')->insertGetId($datos);
            }
            $this->map['empresas'][$r['id']] = $newId;
        }
        $this->info('Empresas migradas: ' . count($this->map['empresas'] ?? []));
    }

    /** Dependencia circular users<->empresas: ahora que ambas existen, completa users.empresa_id/workspace_owner_id. */
    private function vincularUsuariosAEmpresas($legacy): void
    {
        $filas = $legacy->table('users')->get();
        $empresaPorOwnerViejo = $legacy->table('empresas')->pluck('id', 'owner_user_id'); // old_user_id -> old_empresa_id
        foreach ($filas as $row) {
            $r = (array) $row;
            $newUserId = $this->map['users'][$r['id']];

            $empresaIdNueva = null;
            if (isset($empresaPorOwnerViejo[$r['id']])) {
                // Es dueño de una empresa.
                $empresaIdNueva = $this->map['empresas'][$empresaPorOwnerViejo[$r['id']]] ?? null;
            } elseif (! empty($r['workspace_owner_id'])) {
                // Es empleado de otro usuario: su empresa es la del dueño.
                $empresaDelDueno = $empresaPorOwnerViejo[$r['workspace_owner_id']] ?? null;
                $empresaIdNueva = $empresaDelDueno ? ($this->map['empresas'][$empresaDelDueno] ?? null) : null;
            } elseif (! empty($r['empresa_id'])) {
                $empresaIdNueva = $this->map['empresas'][$r['empresa_id']] ?? null;
            }

            DB::table('users')->where('id', $newUserId)->update([
                'empresa_id' => $empresaIdNueva,
                'workspace_owner_id' => ! empty($r['workspace_owner_id']) ? ($this->map['users'][$r['workspace_owner_id']] ?? null) : null,
                'bodega_id' => null, // se corrige en migrarBodegas() si aplica (bodega_id de users es poco usado).
            ]);
        }
    }

    private function migrarEmpresaModulos($legacy): void
    {
        $filas = $legacy->table('empresa_modulos')->get();
        $n = 0;
        foreach ($filas as $row) {
            $r = (array) $row;
            $empresaId = $this->map['empresas'][$r['empresa_id']] ?? null;
            $moduloId = $this->map['modulos'][$r['modulo_id']] ?? null;
            if (! $empresaId || ! $moduloId) continue;
            DB::table('empresa_modulos')->insert([
                'empresa_id' => $empresaId,
                'modulo_id' => $moduloId,
                'estado' => $r['estado'],
                'created_at' => $r['created_at'] ?? now(),
                'updated_at' => $r['updated_at'] ?? now(),
            ]);
            $n++;
        }
        $this->info("empresa_modulos migrados: {$n}");
    }

    private function migrarAjustesAgenda($legacy): void
    {
        $filas = $legacy->table('ajustes_agenda')->get();
        foreach ($filas as $row) {
            $r = (array) $row;
            $ownerId = $this->map['users'][$r['owner_id']] ?? null;
            if (! $ownerId) continue;
            DB::table('ajustes_agenda')->insert([
                'owner_id' => $ownerId,
                'duracion_cita_min' => $r['duracion_cita_min'] ?? 30,
                'buffer_min' => $r['buffer_min'] ?? 0,
                'empresa_id' => $this->map['empresas'][$r['empresa_id']] ?? null,
                'created_at' => $r['created_at'] ?? now(),
                'updated_at' => $r['updated_at'] ?? now(),
            ]);
        }
        $this->info('ajustes_agenda migrados: ' . count($filas));
    }

    private function migrarBodegas($legacy): void
    {
        $filas = $legacy->table('bodegas')->get();
        foreach ($filas as $row) {
            $r = (array) $row;
            $ownerId = $this->map['users'][$r['owner_id']] ?? null;
            if (! $ownerId) continue;
            $newId = DB::table('bodegas')->insertGetId([
                'owner_id' => $ownerId,
                'nombre' => $r['nombre'],
                'direccion' => $r['direccion'] ?? null,
                'telefono' => $r['telefono'] ?? null,
                'ciudad' => $r['ciudad'] ?? null,
                'responsable_id' => $this->map['users'][$r['responsable_id']] ?? $ownerId,
                'activo' => (bool) ($r['activo'] ?? true),
                'es_principal' => (bool) ($r['es_principal'] ?? false),
                'empresa_id' => $this->map['empresas'][$r['empresa_id']] ?? null,
                'created_at' => $r['created_at'] ?? now(),
                'updated_at' => $r['updated_at'] ?? now(),
                'deleted_at' => $r['deleted_at'] ?? null,
            ]);
            $this->map['bodegas'][$r['id']] = $newId;
        }
        $this->info('Bodegas migradas: ' . count($this->map['bodegas'] ?? []));
    }

    private function migrarCategorias($legacy): void
    {
        $filas = $legacy->table('categorias')->get();
        foreach ($filas as $row) {
            $r = (array) $row;
            $ownerId = $this->map['users'][$r['owner_id']] ?? null;
            if (! $ownerId) continue;
            $newId = DB::table('categorias')->insertGetId([
                'owner_id' => $ownerId,
                'nombre' => $r['nombre'],
                'descripcion' => $r['descripcion'] ?? null,
                'empresa_id' => $this->map['empresas'][$r['empresa_id']] ?? null,
                'created_at' => $r['created_at'] ?? now(),
                'updated_at' => $r['updated_at'] ?? now(),
                'deleted_at' => $r['deleted_at'] ?? null,
            ]);
            $this->map['categorias'][$r['id']] = $newId;
        }
        $this->info('Categorías migradas: ' . count($this->map['categorias'] ?? []));
    }

    private function migrarProveedores($legacy): void
    {
        $filas = $legacy->table('proveedores')->get();
        foreach ($filas as $row) {
            $r = (array) $row;
            $ownerId = $this->map['users'][$r['owner_id']] ?? null;
            if (! $ownerId) continue;
            $newId = DB::table('proveedores')->insertGetId([
                'owner_id' => $ownerId,
                'razon_social' => $r['razon_social'],
                'tipo_documento' => $r['tipo_documento'] ?? null,
                'numero_documento' => $r['numero_documento'] ?? null,
                'digito_verificacion' => $r['digito_verificacion'] ?? null,
                'email' => $r['email'] ?? null,
                'telefono' => $r['telefono'] ?? null,
                'direccion' => $r['direccion'] ?? null,
                'terminos_pago' => $r['terminos_pago'] ?? null,
                'created_by' => $this->map['users'][$r['created_by']] ?? $ownerId,
                'empresa_id' => $this->map['empresas'][$r['empresa_id']] ?? null,
                'created_at' => $r['created_at'] ?? now(),
                'updated_at' => $r['updated_at'] ?? now(),
                'deleted_at' => $r['deleted_at'] ?? null,
            ]);
            $this->map['proveedores'][$r['id']] = $newId;
        }
        $this->info('Proveedores migrados: ' . count($this->map['proveedores'] ?? []));
    }

    private function migrarProductos($legacy): void
    {
        $filas = $legacy->table('productos')->get();
        foreach ($filas as $row) {
            $r = (array) $row;
            $ownerId = $this->map['users'][$r['owner_id']] ?? null;
            if (! $ownerId) continue;
            $newId = DB::table('productos')->insertGetId([
                'owner_id' => $ownerId,
                'categoria_id' => $this->map['categorias'][$r['categoria_id']] ?? null,
                'sku' => $r['sku'],
                'codigo_barras' => $r['codigo_barras'] ?? null,
                'nombre' => $r['nombre'],
                'descripcion' => $r['descripcion'] ?? null,
                'is_service' => (bool) ($r['is_service'] ?? false),
                'has_commission' => (bool) ($r['has_commission'] ?? false),
                'commission_type' => $r['commission_type'] ?? null,
                'commission_value' => $r['commission_value'] ?? null,
                'unidad_medida' => $r['unidad_medida'] ?? null,
                'unidad_compra' => $r['unidad_compra'] ?? null,
                'unidades_por_compra' => $r['unidades_por_compra'] ?? null,
                'precio_costo' => $r['precio_costo'] ?? 0,
                'precio_venta' => $r['precio_venta'] ?? 0,
                'imagen_url' => $r['imagen_url'] ?? null,
                'activo' => (bool) ($r['activo'] ?? true),
                'disponible' => (bool) ($r['disponible'] ?? true),
                'created_by' => $this->map['users'][$r['created_by']] ?? $ownerId,
                'empresa_id' => $this->map['empresas'][$r['empresa_id']] ?? null,
                'created_at' => $r['created_at'] ?? now(),
                'updated_at' => $r['updated_at'] ?? now(),
                'deleted_at' => $r['deleted_at'] ?? null,
            ]);
            $this->map['productos'][$r['id']] = $newId;
        }
        $this->info('Productos migrados: ' . count($this->map['productos'] ?? []));
    }

    private function migrarClientes($legacy): void
    {
        $filas = $legacy->table('clientes')->get();
        foreach ($filas as $row) {
            $r = (array) $row;
            $ownerId = $this->map['users'][$r['owner_id']] ?? null;
            if (! $ownerId) continue;
            $newId = DB::table('clientes')->insertGetId([
                'owner_id' => $ownerId,
                'user_id' => ! empty($r['user_id']) ? ($this->map['users'][$r['user_id']] ?? null) : null,
                'nombre_completo' => $r['nombre_completo'],
                'tipo_documento' => $r['tipo_documento'] ?? null,
                'numero_documento' => $r['numero_documento'] ?? null,
                'email' => $r['email'] ?? null,
                'telefono' => $r['telefono'] ?? null,
                'direccion' => $r['direccion'] ?? null,
                'estado' => $r['estado'] ?? 'ACTIVO',
                'seguimiento_comercial' => $r['seguimiento_comercial'] ?? null,
                'created_by' => $this->map['users'][$r['created_by']] ?? $ownerId,
                'empresa_id' => $this->map['empresas'][$r['empresa_id']] ?? null,
                'created_at' => $r['created_at'] ?? now(),
                'updated_at' => $r['updated_at'] ?? now(),
                'deleted_at' => $r['deleted_at'] ?? null,
            ]);
            $this->map['clientes'][$r['id']] = $newId;
        }
        $this->info('Clientes migrados: ' . count($this->map['clientes'] ?? []));
    }

    private function migrarStockPorBodega($legacy): void
    {
        $filas = $legacy->table('stock_por_bodega')->get();
        $n = 0;
        foreach ($filas as $row) {
            $r = (array) $row;
            $productoId = $this->map['productos'][$r['producto_id']] ?? null;
            $bodegaId = $this->map['bodegas'][$r['bodega_id']] ?? null;
            if (! $productoId || ! $bodegaId) continue;
            DB::table('stock_por_bodega')->insert([
                'producto_id' => $productoId,
                'bodega_id' => $bodegaId,
                'cantidad' => $r['cantidad'] ?? 0,
                'stock_minimo' => $r['stock_minimo'] ?? null,
                'costo_promedio' => $r['costo_promedio'] ?? 0,
                'created_at' => $r['created_at'] ?? now(),
                'updated_at' => $r['updated_at'] ?? now(),
            ]);
            $n++;
        }
        $this->info("stock_por_bodega migrado: {$n}");
    }

    private function migrarOperablesEmployees($legacy): void
    {
        $filas = $legacy->table('operables_employees')->get();
        foreach ($filas as $row) {
            $r = (array) $row;
            $ownerId = $this->map['users'][$r['owner_id']] ?? null;
            if (! $ownerId) continue;
            $newId = DB::table('operables_employees')->insertGetId([
                'owner_id' => $ownerId,
                'user_id' => ! empty($r['user_id']) ? ($this->map['users'][$r['user_id']] ?? null) : null,
                'nombre' => $r['nombre'],
                'apellido' => $r['apellido'] ?? null,
                'email' => $r['email'] ?? null,
                'telefono' => $r['telefono'] ?? null,
                'ci_cedula' => $r['ci_cedula'] ?? null,
                'tipo_operario' => $r['tipo_operario'] ?? null,
                'especialidad' => $r['especialidad'] ?? null,
                'comision_default' => $r['comision_default'] ?? null,
                'tipo_comision_default' => $r['tipo_comision_default'] ?? null,
                'activo' => (bool) ($r['activo'] ?? true),
                'empresa_id' => $this->map['empresas'][$r['empresa_id']] ?? null,
                'created_at' => $r['created_at'] ?? now(),
                'updated_at' => $r['updated_at'] ?? now(),
                'deleted_at' => $r['deleted_at'] ?? null,
            ]);
            $this->map['operables_employees'][$r['id']] = $newId;
        }
        $this->info('operables_employees migrados: ' . count($this->map['operables_employees'] ?? []));
    }

    private function migrarFacturasYDetalle($legacy): void
    {
        $filas = $legacy->table('facturas')->get();
        foreach ($filas as $row) {
            $r = (array) $row;
            $ownerId = $this->map['users'][$r['owner_id']] ?? null;
            if (! $ownerId) continue;
            $newId = DB::table('facturas')->insertGetId([
                'owner_id' => $ownerId,
                'numero' => $r['numero'],
                'cliente_id' => $this->map['clientes'][$r['cliente_id']] ?? null,
                'bodega_id' => ! empty($r['bodega_id']) ? ($this->map['bodegas'][$r['bodega_id']] ?? null) : null,
                'mesa_id' => null, // no hay mesas en los datos reales a migrar.
                'fecha' => $r['fecha'],
                'subtotal' => $r['subtotal'] ?? 0,
                'impuestos' => $r['impuestos'] ?? 0,
                'total' => $r['total'] ?? 0,
                'currency' => $r['currency'] ?? 'COP',
                'exchange_rate' => $r['exchange_rate'] ?? null,
                'estado' => $r['estado'],
                'metodo_pago' => $r['metodo_pago'] ?? null,
                'propina' => $r['propina'] ?? null,
                'pdf_url' => $r['pdf_url'] ?? null,
                'firma_url' => $r['firma_url'] ?? null,
                'notas' => $r['notas'] ?? null,
                'created_by' => $this->map['users'][$r['created_by']] ?? $ownerId,
                'empresa_id' => $this->map['empresas'][$r['empresa_id']] ?? null,
                'created_at' => $r['created_at'] ?? now(),
                'updated_at' => $r['updated_at'] ?? now(),
                'deleted_at' => $r['deleted_at'] ?? null,
            ]);
            $this->map['facturas'][$r['id']] = $newId;
        }
        $this->info('Facturas migradas: ' . count($this->map['facturas'] ?? []));

        $detalles = $legacy->table('factura_detalle')->get();
        $n = 0;
        foreach ($detalles as $row) {
            $r = (array) $row;
            $facturaId = $this->map['facturas'][$r['factura_id']] ?? null;
            if (! $facturaId) continue;
            DB::table('factura_detalle')->insert([
                'factura_id' => $facturaId,
                'producto_id' => ! empty($r['producto_id']) ? ($this->map['productos'][$r['producto_id']] ?? null) : null,
                'descripcion' => $r['descripcion'],
                'cantidad' => $r['cantidad'],
                'precio_unitario' => $r['precio_unitario'],
                'impuesto_porcentaje' => $r['impuesto_porcentaje'] ?? 0,
                'subtotal' => $r['subtotal'],
                'impuesto' => $r['impuesto'] ?? 0,
                'created_at' => $r['created_at'] ?? now(),
                'updated_at' => $r['updated_at'] ?? now(),
            ]);
            $n++;
        }
        $this->info("factura_detalle migrado: {$n}");
    }

    private function migrarFacturaPagos($legacy): void
    {
        $filas = $legacy->table('factura_pagos')->get();
        $n = 0;
        foreach ($filas as $row) {
            $r = (array) $row;
            $facturaId = $this->map['facturas'][$r['factura_id']] ?? null;
            $ownerId = $this->map['users'][$r['owner_id']] ?? null;
            if (! $facturaId || ! $ownerId) continue;
            DB::table('factura_pagos')->insert([
                'owner_id' => $ownerId,
                'empresa_id' => $this->map['empresas'][$r['empresa_id']] ?? null,
                'factura_id' => $facturaId,
                'monto' => $r['monto'],
                'metodo_pago' => $r['metodo_pago'],
                'fecha' => $r['fecha'],
                'nota' => $r['nota'] ?? null,
                'created_by' => $this->map['users'][$r['created_by']] ?? $ownerId,
                'created_at' => $r['created_at'] ?? now(),
                'updated_at' => $r['updated_at'] ?? now(),
            ]);
            $n++;
        }
        $this->info("factura_pagos migrado: {$n}");
    }

    private function migrarMetodosPagoCobro($legacy): void
    {
        $filas = $legacy->table('metodos_pago_cobro')->get();
        $n = 0;
        foreach ($filas as $row) {
            $r = (array) $row;
            $ownerId = $this->map['users'][$r['owner_id']] ?? null;
            if (! $ownerId) continue;
            DB::table('metodos_pago_cobro')->insert([
                'owner_id' => $ownerId,
                'empresa_id' => $this->map['empresas'][$r['empresa_id']] ?? null,
                'tipo' => $r['tipo'],
                'nombre' => $r['nombre'],
                'numero_cuenta' => $r['numero_cuenta'] ?? null,
                'enlace' => $r['enlace'] ?? null,
                'qr_url' => $r['qr_url'] ?? null,
                'activo' => (bool) ($r['activo'] ?? true),
                'orden' => $r['orden'] ?? 0,
                'created_at' => $r['created_at'] ?? now(),
                'updated_at' => $r['updated_at'] ?? now(),
                'deleted_at' => $r['deleted_at'] ?? null,
            ]);
            $n++;
        }
        $this->info("metodos_pago_cobro migrado: {$n}");
    }

    private function migrarCajaSesiones($legacy): void
    {
        $filas = $legacy->table('caja_sesiones')->get();
        foreach ($filas as $row) {
            $r = (array) $row;
            $ownerId = $this->map['users'][$r['owner_id']] ?? null;
            if (! $ownerId) continue;
            $newId = DB::table('caja_sesiones')->insertGetId([
                'owner_id' => $ownerId,
                'user_id' => $this->map['users'][$r['user_id']] ?? $ownerId,
                'bodega_id' => ! empty($r['bodega_id']) ? ($this->map['bodegas'][$r['bodega_id']] ?? null) : null,
                'estado' => $r['estado'],
                'monto_apertura' => $r['monto_apertura'] ?? 0,
                'monto_esperado' => $r['monto_esperado'] ?? null,
                'monto_cierre' => $r['monto_cierre'] ?? null,
                'descuadre' => $r['descuadre'] ?? null,
                'notas_apertura' => $r['notas_apertura'] ?? null,
                'notas_cierre' => $r['notas_cierre'] ?? null,
                'abierta_at' => $r['abierta_at'] ?? $r['created_at'] ?? now(),
                'cerrada_at' => $r['cerrada_at'] ?? null,
                'empresa_id' => $this->map['empresas'][$r['empresa_id']] ?? null,
                'created_at' => $r['created_at'] ?? now(),
                'updated_at' => $r['updated_at'] ?? now(),
            ]);
            $this->map['caja_sesiones'][$r['id']] = $newId;
        }
        $this->info('caja_sesiones migradas: ' . count($this->map['caja_sesiones'] ?? []));
    }

    private function migrarGastos($legacy): void
    {
        $filas = $legacy->table('gastos')->get();
        $n = 0;
        foreach ($filas as $row) {
            $r = (array) $row;
            $ownerId = $this->map['users'][$r['owner_id']] ?? null;
            if (! $ownerId) continue;
            DB::table('gastos')->insert([
                'owner_id' => $ownerId,
                'user_id' => $this->map['users'][$r['user_id']] ?? $ownerId,
                'caja_sesion_id' => ! empty($r['caja_sesion_id']) ? ($this->map['caja_sesiones'][$r['caja_sesion_id']] ?? null) : null,
                'bodega_id' => ! empty($r['bodega_id']) ? ($this->map['bodegas'][$r['bodega_id']] ?? null) : null,
                'categoria' => $r['categoria'],
                'descripcion' => $r['descripcion'],
                'monto' => $r['monto'],
                'fecha' => $r['fecha'],
                'empresa_id' => $this->map['empresas'][$r['empresa_id']] ?? null,
                'created_at' => $r['created_at'] ?? now(),
                'updated_at' => $r['updated_at'] ?? now(),
                'deleted_at' => $r['deleted_at'] ?? null,
            ]);
            $n++;
        }
        $this->info("Gastos migrados: {$n}");
    }

    private function migrarMovimientosInventario($legacy): void
    {
        $filas = $legacy->table('movimientos_inventario')->get();
        $n = 0;
        foreach ($filas as $row) {
            $r = (array) $row;
            $ownerId = $this->map['users'][$r['owner_id']] ?? null;
            $productoId = $this->map['productos'][$r['producto_id']] ?? null;
            if (! $ownerId || ! $productoId) continue;

            // referencia_id apunta a una factura cuando referencia_tipo='FACTURA'; remapea si aplica.
            $referenciaId = $r['referencia_id'] ?? null;
            if ($referenciaId && ($r['referencia_tipo'] ?? null) === 'FACTURA') {
                $referenciaId = $this->map['facturas'][$referenciaId] ?? null;
            }

            DB::table('movimientos_inventario')->insert([
                'owner_id' => $ownerId,
                'producto_id' => $productoId,
                'tipo' => $r['tipo'],
                'motivo' => $r['motivo'] ?? null,
                'bodega_origen_id' => ! empty($r['bodega_origen_id']) ? ($this->map['bodegas'][$r['bodega_origen_id']] ?? null) : null,
                'bodega_destino_id' => ! empty($r['bodega_destino_id']) ? ($this->map['bodegas'][$r['bodega_destino_id']] ?? null) : null,
                'cantidad' => $r['cantidad'],
                'costo_unitario' => $r['costo_unitario'] ?? null,
                'costo_promedio_resultante' => $r['costo_promedio_resultante'] ?? null,
                'stock_resultante' => $r['stock_resultante'] ?? null,
                'referencia_tipo' => $r['referencia_tipo'] ?? null,
                'referencia_id' => $referenciaId,
                'usuario_id' => $this->map['users'][$r['usuario_id']] ?? $ownerId,
                'empresa_id' => $this->map['empresas'][$r['empresa_id']] ?? null,
                'created_at' => $r['created_at'] ?? now(),
                'updated_at' => $r['updated_at'] ?? now(),
            ]);
            $n++;
        }
        $this->info("movimientos_inventario migrado: {$n}");
    }

    private function migrarHorariosLaborales($legacy): void
    {
        $filas = $legacy->table('horarios_laborales')->get();
        $n = 0;
        foreach ($filas as $row) {
            $r = (array) $row;
            $ownerId = $this->map['users'][$r['owner_id']] ?? null;
            if (! $ownerId) continue;
            DB::table('horarios_laborales')->insert([
                'owner_id' => $ownerId,
                'bodega_id' => ! empty($r['bodega_id']) ? ($this->map['bodegas'][$r['bodega_id']] ?? null) : null,
                'dia_semana' => $r['dia_semana'],
                'hora_inicio' => $r['hora_inicio'],
                'hora_fin' => $r['hora_fin'],
                'activo' => (bool) ($r['activo'] ?? true),
                'empresa_id' => $this->map['empresas'][$r['empresa_id']] ?? null,
                'created_at' => $r['created_at'] ?? now(),
                'updated_at' => $r['updated_at'] ?? now(),
            ]);
            $n++;
        }
        $this->info("horarios_laborales migrado: {$n}");
    }

    private function migrarNotas($legacy): void
    {
        $filas = $legacy->table('notas')->get();
        $n = 0;
        foreach ($filas as $row) {
            $r = (array) $row;
            $createdBy = ! empty($r['created_by']) ? ($this->map['users'][$r['created_by']] ?? null) : null;
            DB::table('notas')->insert([
                'titulo' => $r['titulo'] ?? null,
                'contenido' => $r['contenido'] ?? null,
                'cliente_id' => ! empty($r['cliente_id']) ? ($this->map['clientes'][$r['cliente_id']] ?? null) : null,
                'created_by' => $createdBy,
                'created_at' => $r['created_at'] ?? now(),
                'updated_at' => $r['updated_at'] ?? now(),
            ]);
            $n++;
        }
        $this->info("Notas migradas: {$n}");
    }
}
