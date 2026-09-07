<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use App\Models\Bodega;
use App\Models\Cliente;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Models\UserFuncionalidad;
use App\Support\Funcionalidades;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\CreditPackage;

/**
 * Panel del Super Administrador: gestión de usuarios y licencias.
 * Todas las rutas de este controlador están protegidas por el middleware 'superadmin'.
 */
class UsuarioAdminController extends Controller
{
    /** Conteo de clientes por propietario (saltando el filtro multi-inquilino). */
    private function clientesPorOwner(): \Illuminate\Support\Collection
    {
        return Cliente::withoutGlobalScopes()
            ->selectRaw('owner_id, COUNT(*) as total')
            ->groupBy('owner_id')
            ->pluck('total', 'owner_id');
    }

    /** Serializa un usuario con sus datos de plan/estado/clientes. */
    private function serializar(User $u, \Illuminate\Support\Collection $conteos): array
    {
        $limite = $u->limiteClientesEfectivo();
        $usados = (int) ($conteos[$u->id] ?? 0);

        return [
            'id' => $u->id,
            'name' => $u->name,
            'tipo_documento' => $u->tipo_documento,
            'numero_documento' => $u->numero_documento,
            'email' => $u->email,
            'telefono' => $u->telefono,
            'rol' => $u->rol?->nombre,
            'workspace_owner_id' => $u->workspace_owner_id,
            'bodega' => $u->bodega ? ['id' => $u->bodega->id, 'nombre' => $u->bodega->nombre] : null,
            'plan' => $u->plan ? ['id' => $u->plan->id, 'nombre' => $u->plan->nombre] : null,
            'estado' => $u->estado,
            // Solo existe mientras la cuenta está pendiente de activación; el
            // super-admin es el único que puede verlo (este panel está detrás
            // del middleware 'superadmin') y siempre es aleatorio por registro,
            // nunca un valor fijo.
            'codigo_activacion' => $u->estado === 'PENDIENTE_ACTIVACION' ? $u->codigo_activacion : null,
            'modo_cobro' => $u->modo_cobro,
            'membresia_vence_at' => $u->membresia_vence_at?->toIso8601String(),
            'membresia_vencida' => $u->membresiaVencida(),
            'es_super_admin' => (bool) $u->es_super_admin,
            'limite_clientes' => $limite === PHP_INT_MAX ? null : $limite,
            'limite_manual' => $u->limite_clientes,
            'clientes_usados' => $usados,
            'clientes_disponibles' => $limite === PHP_INT_MAX ? null : max(0, $limite - $usados),
            'fecha_registro' => $u->created_at?->toIso8601String(),
            'ultimo_acceso' => $u->ultimo_acceso?->toIso8601String(),
        ];
    }

    /** Listado de usuarios registrados (módulo "Usuarios Registrados"). */
    public function index(Request $request): JsonResponse
    {
        $q = User::with('rol', 'plan', 'bodega');

        if ($buscar = $request->query('buscar')) {
            $q->where(function ($sub) use ($buscar) {
                $sub->where('name', 'like', "%{$buscar}%")
                    ->orWhere('email', 'like', "%{$buscar}%")
                    ->orWhere('numero_documento', 'like', "%{$buscar}%");
            });
        }
        if ($estado = $request->query('estado')) {
            $q->where('estado', $estado);
        }

        $usuarios = $q->orderByDesc('id')->get();
        $conteos = $this->clientesPorOwner();

        return response()->json(
            $usuarios->map(fn ($u) => $this->serializar($u, $conteos))->values()
        );
    }

    /** Sección "Administración de Licencias": foco en plan / límite / consumo. */
    public function licencias(): JsonResponse
    {
        $usuarios = User::with('plan')->orderByDesc('id')->get();
        $conteos = $this->clientesPorOwner();

        return response()->json(
            $usuarios->map(fn ($u) => $this->serializar($u, $conteos))->values()
        );
    }

    public function update(Request $request, User $usuario): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'tipo_documento' => ['nullable', 'in:CC,CE,NIT,PAS'],
            'numero_documento' => ['nullable', 'string', 'max:50'],
            'telefono' => ['nullable', 'string', 'max:50'],
            'email' => ['required', 'email', 'unique:users,email,' . $usuario->id],
            // Cobro SaaS: membresía mensual o prepago ($500 por factura).
            'modo_cobro' => ['nullable', 'in:membresia,prepago'],
            'membresia_vence_at' => ['nullable', 'date'],
        ]);

        $usuario->update($data);
        $conteos = $this->clientesPorOwner();

        return response()->json($this->serializar($usuario->fresh('rol', 'plan'), $conteos));
    }

    /** Crea un empleado/administrador de sucursal dentro del workspace del usuario autenticado. */
    public function crearEmpleado(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['nullable', 'string', 'min:8'],
            'rol_id' => ['required', 'exists:roles,id'],
            'bodega_id' => ['required', 'exists:bodegas,id'],
            'telefono' => ['nullable', 'string', 'max:50'],
            // Ficha de empleado del taller (mecánico/técnico) a la que se vincula esta cuenta.
            'operables_employee_id' => ['nullable', 'exists:operables_employees,id'],
        ]);

        $ownerId = $request->user()->workspaceOwnerId();
        $bodega = Bodega::where('id', $data['bodega_id'])->firstOrFail();
        abort_unless((int) $bodega->owner_id === $ownerId || $request->user()->esSuperAdmin(), 403, 'La bodega no pertenece a tu negocio.');

        $empleadoTaller = null;
        if (! empty($data['operables_employee_id'])) {
            $empleadoTaller = \App\Models\OperablesEmployee::withoutGlobalScopes()->findOrFail($data['operables_employee_id']);
            abort_unless((int) $empleadoTaller->owner_id === $ownerId || $request->user()->esSuperAdmin(), 403, 'El empleado no pertenece a tu negocio.');
        }

        // Generamos la contraseña en plano y la encriptamos antes de guardarla en la Base de Datos
        $passwordPlano = $data['password'] ?? Str::password(12);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($passwordPlano), // Encriptación correcta
            'rol_id' => $data['rol_id'],
            'workspace_owner_id' => $ownerId,
            'bodega_id' => $data['bodega_id'],
            'telefono' => $data['telefono'] ?? null,
            'activo' => true,
            'estado' => 'ACTIVO',
        ]);

        // Vincula la cuenta de acceso con la ficha del taller (el rol Mecanico filtra sus órdenes por aquí).
        if ($empleadoTaller) {
            $empleadoTaller->update(['user_id' => $user->id]);
        }

        Auditoria::registrar($request->user()->id, $user->id, 'USUARIO', 'CREAR_EMPLEADO', null, $bodega->nombre);

        $conteos = $this->clientesPorOwner();
        return response()->json($this->serializar($user->fresh('rol', 'plan', 'bodega'), $conteos), 201);
    }

    /**
     * Crear empleado rápido: sólo nombre y apellido, opcional bodega_id.
     * El empleado queda atado al workspace del administrador y a la bodega del admin
     * si no se especifica `bodega_id`.
     */
    public function crearEmpleadoRapido(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'apellido' => ['required', 'string', 'max:255'],
            'bodega_id' => ['nullable', 'exists:bodegas,id'],
        ]);

        $ownerId = $request->user()->workspaceOwnerId();

        // Determina la bodega destino: la provista (si pertenece) o la del admin
        $bodegaId = $data['bodega_id'] ?? $request->user()->bodega_id;
        if ($bodegaId) {
            $bodega = Bodega::where('id', $bodegaId)->firstOrFail();
            abort_unless((int) $bodega->owner_id === $ownerId || $request->user()->esSuperAdmin(), 403, 'La bodega no pertenece a tu negocio.');
        } else {
            $bodega = Bodega::where('owner_id', $ownerId)->first();
            $bodegaId = $bodega?->id ?? null;
        }

        $rolId = Role::where('nombre', 'Empleado')->value('id') ?? Role::where('nombre', 'Usuario')->value('id');
        $passwordPlano = Str::password(12);

        $user = User::create([
            'name' => trim($data['nombre'] . ' ' . $data['apellido']),
            'email' => Str::slug($data['nombre'] . '.' . $data['apellido']) . '.' . Str::random(4) . '@noemail.local',
            'password' => Hash::make($passwordPlano), // Encriptación correcta
            'rol_id' => $rolId,
            'workspace_owner_id' => $ownerId,
            'bodega_id' => $bodegaId,
            'activo' => true,
            'estado' => 'ACTIVO',
        ]);

        Auditoria::registrar($request->user()->id, $user->id, 'USUARIO', 'CREAR_EMPLEADO_RAPIDO', null, $bodega?->nombre ?? null);

        $conteos = $this->clientesPorOwner();
        return response()->json($this->serializar($user->fresh('rol', 'plan', 'bodega'), $conteos), 201);
    }

    public function cambiarEstado(Request $request, User $usuario): JsonResponse
    {
        if ($usuario->es_super_admin) {
            return response()->json(['message' => 'No se puede cambiar el estado del Super Administrador.'], 422);
        }

        $data = $request->validate([
            'estado' => ['required', 'in:ACTIVO,SUSPENDIDO,DESACTIVADO'],
        ]);

        $anterior = $usuario->estado;

        // Activar una cuenta pendiente desde aquí (sin pasar por el código)
        // también debe arrancar la prueba gratis; si no, la empresa se queda
        // sin fecha de vencimiento y nunca se bloquea.
        if ($anterior === 'PENDIENTE_ACTIVACION' && $data['estado'] === 'ACTIVO') {
            $usuario->activarPendiente();
        } else {
            $usuario->update([
                'estado' => $data['estado'],
                'activo' => $data['estado'] === 'ACTIVO',
            ]);
        }

        // Multiempresa: si el usuario es el dueño, el estado aplica a toda su empresa.
        if ($usuario->es_admin_empresa || $usuario->esPropietario()) {
            $usuario->empresaDeCobro()?->update([
                'estado' => $data['estado'],
                'activo' => $data['estado'] === 'ACTIVO',
            ]);
        }

        // Si queda inactivo, revoca sus sesiones.
        if ($data['estado'] !== 'ACTIVO') {
            $usuario->tokens()->delete();
        }

        Auditoria::registrar($request->user()->id, $usuario->id, 'ESTADO', null, $anterior, $data['estado']);

        $conteos = $this->clientesPorOwner();
        return response()->json($this->serializar($usuario->fresh('rol', 'plan'), $conteos));
    }

    public function cambiarPlan(Request $request, User $usuario): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'exists:plans,id'],
        ]);

        $anterior = $usuario->plan?->nombre;
        $usuario->update(['plan_id' => $data['plan_id']]);
        // Multiempresa: el plan vive en la empresa GOBERNANTE del grupo (si
        // tiene negocios vinculados, un solo plan cubre a todos).
        $usuario->empresaDeCobro()?->empresaGobernante()?->update(['plan_id' => $data['plan_id']]);
        $usuario->load('plan');

        Auditoria::registrar($request->user()->id, $usuario->id, 'PLAN', null, $anterior, $usuario->plan?->nombre);

        // Notifica al usuario afectado (notificación de usuario, solo él la ve).
        app(\App\Services\Notificador::class)->aUsuario(
            $usuario->id, 'PLAN', 'Tu plan fue actualizado',
            "Tu plan ahora es {$usuario->plan?->nombre}."
        );

        $conteos = $this->clientesPorOwner();
        return response()->json($this->serializar($usuario->fresh('rol', 'plan'), $conteos));
    }

    public function cambiarLimite(Request $request, User $usuario): JsonResponse
    {
        $data = $request->validate([
            // null = volver a usar el límite del plan.
            'limite_clientes' => ['nullable', 'integer', 'min:0'],
        ]);

        $anterior = $usuario->limite_clientes;
        $usuario->update(['limite_clientes' => $data['limite_clientes'] ?? null]);
        // Multiempresa: el límite vive en la empresa GOBERNANTE del grupo.
        $usuario->empresaDeCobro()?->empresaGobernante()?->update(['limite_clientes' => $data['limite_clientes'] ?? null]);

        Auditoria::registrar($request->user()->id, $usuario->id, 'LIMITE', null, (string) $anterior, (string) ($data['limite_clientes'] ?? 'plan'));

        $conteos = $this->clientesPorOwner();
        return response()->json($this->serializar($usuario->fresh('rol', 'plan'), $conteos));
    }

    /**
     * Eliminación LÓGICA: el usuario no podrá iniciar sesión; la información
     * permanece para auditoría (soft delete).
     */
    public function eliminar(Request $request, User $usuario): JsonResponse
    {
        if ($usuario->es_super_admin) {
            return response()->json(['message' => 'No se puede eliminar al Super Administrador.'], 422);
        }

        $usuario->tokens()->delete();
        $usuario->update(['estado' => 'DESACTIVADO', 'activo' => false]);
        $usuario->delete(); // soft delete

        Auditoria::registrar($request->user()->id, $usuario->id, 'ELIMINAR', 'LOGICA', $usuario->email, $usuario->name);

        return response()->json(['message' => 'Usuario eliminado (eliminación lógica). Su información queda disponible para auditoría.']);
    }

    /**
     * Eliminación PERMANENTE: borra el usuario y TODA su información asociada
     * (clientes, citas, facturas, inventario, productos, notas, etc.). Irreversible.
     */
    public function eliminarPermanente(Request $request, User $usuario): JsonResponse
    {
        if ($usuario->es_super_admin) {
            return response()->json(['message' => 'No se puede eliminar al Super Administrador.'], 422);
        }

        $userId = $usuario->id;
        $email = $usuario->email;
        $nombre = $usuario->name;

        // Tablas de datos del inquilino (por owner_id), en un orden que respeta
        // las 3 únicas restricciones RESTRICT del esquema (ordenes_compra.proveedor_id,
        // orden_compra_detalle.producto_id y facturas.cliente_id): 'ordenes_compra'
        // debe borrarse antes que 'productos'/'proveedores', y 'facturas' antes que
        // 'clientes'. El resto de relaciones son CASCADE o SET NULL a nivel de base
        // de datos, así que se resuelven solas sin tocar session_replication_role
        // (Postgres administrado, como el de Render, no da permiso para eso).
        $tablas = [
            'ordenes_compra', 'facturas', 'clientes', 'citas', 'notas', 'productos',
            'movimientos_inventario', 'proveedores', 'categorias', 'bodegas', 'servicios',
            'stock_por_bodega', 'documentos', 'horarios_laborales', 'bloqueos_agenda', 'ajustes_agenda',
        ];

        DB::transaction(function () use ($userId, $tablas, $usuario) {
            // Hijos (detalles) de los registros del propietario: ya se borran solos
            // vía CASCADE al borrar el padre de arriba, pero se dejan explícitos
            // por claridad/seguridad (no hace daño borrar dos veces lo mismo).
            DB::table('factura_detalle')->whereIn('factura_id', fn ($q) => $q->select('id')->from('facturas')->where('owner_id', $userId))->delete();
            DB::table('orden_compra_detalle')->whereIn('orden_compra_id', fn ($q) => $q->select('id')->from('ordenes_compra')->where('owner_id', $userId))->delete();
            DB::table('producto_proveedor')->whereIn('producto_id', fn ($q) => $q->select('id')->from('productos')->where('owner_id', $userId))->delete();

            foreach ($tablas as $t) {
                DB::table($t)->where('owner_id', $userId)->delete();
            }

            DB::table('notificaciones')->where('user_id', $userId)->delete();
            DB::table('user_funcionalidades')->where('user_id', $userId)->delete();

            $usuario->tokens()->delete();
            $usuario->forceDelete();
        });

        Auditoria::registrar($request->user()->id, null, 'ELIMINAR', 'PERMANENTE', $email, $nombre);

        return response()->json(['message' => "Usuario {$email} y toda su información fueron eliminados permanentemente."]);
    }

    // ===== Equipo del negocio (para el dueño/Administrador, no super-admin) =====

    /** Catálogo de roles con sus permisos: para elegir rol al crear/editar un empleado. */
    public function roles(): JsonResponse
    {
        return response()->json(
            Role::with('permisos:id,clave,descripcion')
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'descripcion'])
        );
    }

    /** Equipo del workspace del usuario autenticado (él mismo + los empleados que creó). */
    public function miEquipo(Request $request): JsonResponse
    {
        $ownerId = $request->user()->workspaceOwnerId();

        $usuarios = User::with('rol:id,nombre', 'bodega:id,nombre')
            ->where(function ($q) use ($ownerId) {
                $q->where('id', $ownerId)->orWhere('workspace_owner_id', $ownerId);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'telefono', 'rol_id', 'bodega_id', 'estado', 'created_at']);

        return response()->json($usuarios->map(fn ($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'telefono' => $u->telefono,
            'rol' => $u->rol?->nombre,
            'rol_id' => $u->rol_id,
            'bodega' => $u->bodega?->nombre,
            'estado' => $u->estado,
            'es_propietario' => $u->id === $ownerId,
            'fecha_registro' => $u->created_at?->toIso8601String(),
        ]));
    }

    /** El dueño/Administrador cambia el rol o la bodega de un empleado de SU equipo (no a sí mismo). */
    public function actualizarEquipo(Request $request, User $usuario): JsonResponse
    {
        $ownerId = $request->user()->workspaceOwnerId();
        abort_unless($usuario->workspaceOwnerId() === $ownerId, 403, 'Ese usuario no pertenece a tu equipo.');
        abort_if($usuario->id === $ownerId, 422, 'No puedes cambiar tu propio rol.');

        $data = $request->validate([
            'rol_id' => ['required', 'exists:roles,id'],
            'bodega_id' => ['nullable', 'exists:bodegas,id'],
        ]);

        if (! empty($data['bodega_id'])) {
            $bodega = Bodega::where('id', $data['bodega_id'])->firstOrFail();
            abort_unless((int) $bodega->owner_id === $ownerId, 403, 'La bodega no pertenece a tu negocio.');
        }

        $rolAnterior = $usuario->rol?->nombre;
        $usuario->update(['rol_id' => $data['rol_id'], 'bodega_id' => $data['bodega_id'] ?? $usuario->bodega_id]);

        Auditoria::registrar($request->user()->id, $usuario->id, 'EQUIPO_ROL', null, $rolAnterior, $usuario->fresh('rol')->rol?->nombre);

        return response()->json(['message' => 'Rol actualizado.']);
    }

    /** El dueño/Administrador activa/suspende/desactiva a un empleado de SU equipo. */
    public function estadoEquipo(Request $request, User $usuario): JsonResponse
    {
        $ownerId = $request->user()->workspaceOwnerId();
        abort_unless($usuario->workspaceOwnerId() === $ownerId, 403, 'Ese usuario no pertenece a tu equipo.');
        abort_if($usuario->id === $ownerId, 422, 'No puedes cambiar tu propio estado.');

        $data = $request->validate(['estado' => ['required', 'in:ACTIVO,SUSPENDIDO,DESACTIVADO']]);

        $anterior = $usuario->estado;
        $usuario->update(['estado' => $data['estado'], 'activo' => $data['estado'] === 'ACTIVO']);
        if ($data['estado'] !== 'ACTIVO') {
            $usuario->tokens()->delete();
        }

        Auditoria::registrar($request->user()->id, $usuario->id, 'EQUIPO_ESTADO', null, $anterior, $data['estado']);

        return response()->json(['message' => 'Estado actualizado.']);
    }

    // ===== Control de Funcionalidades (por usuario) =====

    /** Matriz de funcionalidades de un usuario: catálogo + estado efectivo + override. */
    public function funcionalidades(User $usuario): JsonResponse
    {
        $overrides = UserFuncionalidad::where('user_id', $usuario->id)->pluck('estado', 'clave');

        $items = [];
        foreach (Funcionalidades::CATALOGO as $clave => $label) {
            $items[] = [
                'clave' => $clave,
                'label' => $label,
                'estado' => Funcionalidades::estadoEfectivo($usuario, $clave),
                'override' => $overrides[$clave] ?? null,
                'por_plan' => Funcionalidades::estadoPorPlan($usuario->plan?->nombre, $clave),
            ];
        }

        return response()->json([
            'usuario' => ['id' => $usuario->id, 'name' => $usuario->name, 'plan' => $usuario->plan?->nombre],
            'estados' => Funcionalidades::ESTADOS,
            'items' => $items,
        ]);
    }

    /** Guarda overrides de funcionalidades de un usuario. */
    public function guardarFuncionalidades(Request $request, User $usuario): JsonResponse
    {
        $data = $request->validate([
            'clave' => ['required', 'string', 'in:' . implode(',', array_keys(Funcionalidades::CATALOGO))],
            'estado' => ['required', 'in:' . implode(',', Funcionalidades::ESTADOS)],
        ]);

        $anterior = Funcionalidades::estadoEfectivo($usuario, $data['clave']);

        UserFuncionalidad::updateOrCreate(
            ['user_id' => $usuario->id, 'clave' => $data['clave']],
            ['estado' => $data['estado']],
        );

        Auditoria::registrar($request->user()->id, $usuario->id, 'FUNCIONALIDAD', $data['clave'], $anterior, $data['estado']);

        return $this->funcionalidades($usuario->fresh('plan'));
    }

    /** Restablece las funcionalidades del usuario a los valores por defecto de su plan. */
    public function aplicarPlanFuncionalidades(Request $request, User $usuario): JsonResponse
    {
        UserFuncionalidad::where('user_id', $usuario->id)->delete();
        Auditoria::registrar($request->user()->id, $usuario->id, 'FUNCIONALIDAD', '*', 'override', 'plan:' . ($usuario->plan?->nombre ?? '-'));

        return $this->funcionalidades($usuario->fresh('plan'));
    }

    /** Bitácora de cambios del Super Administrador. */
    public function auditorias(): JsonResponse
    {
        $q = Auditoria::with(['admin:id,name', 'usuario:id,name,email', 'bodega:id,nombre']);
        $actual = request()->user();

        if ($actual && ! $actual->esSuperAdmin()) {
            $idsEquipo = User::where('id', $actual->workspaceOwnerId())
                ->orWhere('workspace_owner_id', $actual->workspaceOwnerId())
                ->pluck('id');

            $q->where(function ($sub) use ($idsEquipo) {
                $sub->whereIn('admin_id', $idsEquipo)
                    ->orWhereIn('usuario_id', $idsEquipo);
            });

            if ($actual->estaLimitadoABodega()) {
                $q->where('bodega_id', $actual->bodega_id);
            }
        }

        if ($bodegaId = request()->query('bodega_id')) {
            $q->where('bodega_id', $bodegaId);
        }
        if ($usuarioId = request()->query('usuario_id')) {
            $q->where(function ($sub) use ($usuarioId) {
                $sub->where('admin_id', $usuarioId)->orWhere('usuario_id', $usuarioId);
            });
        }
        if ($accion = request()->query('accion')) {
            $q->where('accion', $accion);
        }

        $registros = $q
            ->latest()
            ->limit(200)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'admin' => $a->admin?->name,
                'usuario' => $a->usuario?->name,
                'usuario_email' => $a->usuario?->email,
                'bodega' => $a->bodega?->nombre,
                'accion' => $a->accion,
                'funcionalidad' => $a->funcionalidad,
                'estado_anterior' => $a->estado_anterior,
                'estado_nuevo' => $a->estado_nuevo,
                'fecha' => $a->created_at?->toIso8601String(),
            ]);

        return response()->json($registros);
    }

    public function restablecerPassword(Request $request, User $usuario): JsonResponse
    {
        $data = $request->validate([
            // Si no envían contraseña, se genera una temporal.
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        $nueva = $data['password'] ?? Str::password(10);
        $usuario->update(['password' => Hash::make($nueva)]);
        $usuario->tokens()->delete(); // cierra sesiones activas

        return response()->json([
            'message' => 'Contraseña restablecida correctamente.',
            'password_temporal' => $nueva,
        ]);
    }
}