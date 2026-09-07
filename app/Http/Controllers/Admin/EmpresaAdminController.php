<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ActualizarEmpresaRequest;
use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\EmpresaModulo;
use App\Models\Modulo;
use App\Models\TipoNegocio;
use App\Services\Notificador;
use App\Support\Funcionalidades;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Panel del Super Administrador: gestión de EMPRESAS (tenants del SaaS).
 * Estado, plan, límite, tipo de negocio y módulos por empresa.
 * Todas las rutas van protegidas por el middleware 'superadmin'.
 */
class EmpresaAdminController extends Controller
{
    /** Serializa una empresa con su dueño, plan y consumo. */
    private function serializar(Empresa $e): array
    {
        // Si tiene negocios vinculados ("Mis negocios"), el plan/membresía real
        // es el de la empresa GOBERNANTE del grupo (la más antigua) — un solo
        // plan cubre a todas; el consumo (clientes/citas) se suma entre todas.
        $gobernante = $e->empresaGobernante();
        $grupoIds = $e->grupoEmpresaIds();

        return [
            'id' => $e->id,
            'nombre' => $e->nombre,
            'tipo_documento' => $e->tipo_documento,
            'numero_documento' => $e->numero_documento,
            'telefono' => $e->telefono,
            'email' => $e->email,
            'email_facturacion' => $e->email_facturacion,
            'tipo_negocio' => $e->tipoNegocio?->only(['id', 'clave', 'nombre']),
            'dueno' => $e->owner?->only(['id', 'name', 'email']),
            'dueno_estado' => $e->owner?->estado,
            // Solo visible mientras la cuenta está pendiente de activación
            // (el super-admin lo entrega manualmente al dueño).
            'codigo_activacion' => $e->owner?->estado === 'PENDIENTE_ACTIVACION' ? $e->owner?->codigo_activacion : null,
            'dueno_veces_login' => $e->owner?->veces_login,
            'dueno_ultimo_acceso' => $e->owner?->ultimo_acceso?->toIso8601String(),
            'usuarios' => $e->usuarios()->count(),
            'plan' => $gobernante->plan ? ['id' => $gobernante->plan->id, 'nombre' => $gobernante->plan->nombre] : null,
            'modo_cobro' => $gobernante->modo_cobro,
            'membresia_vence_at' => $gobernante->membresia_vence_at?->toIso8601String(),
            'membresia_vencida' => $e->membresiaVencida(),
            'estado' => $e->estado,
            'limite_clientes' => $e->limiteClientesEfectivo() ?: null,
            'limite_manual' => $gobernante->limite_clientes,
            'clientes_usados' => $e->clientesUsados(),
            'limite_citas' => $e->limiteCitasEfectivo() ?: null,
            'limite_citas_manual' => $gobernante->limite_citas,
            'citas_usadas' => $e->citasUsadas(),
            'fecha_registro' => $e->created_at?->toIso8601String(),
            // Grupo de "Mis negocios": 1 = sin vínculos (comportamiento de siempre).
            'negocios_vinculados' => count($grupoIds),
            'empresa_gobernante' => $gobernante->id !== $e->id ? $gobernante->nombre : null,
        ];
    }

    /** Listado de empresas registradas. */
    public function index(Request $request): JsonResponse
    {
        $q = Empresa::with('owner:id,name,email', 'plan:id,nombre', 'tipoNegocio:id,clave,nombre');

        if ($buscar = $request->query('buscar')) {
            $q->where(function ($sub) use ($buscar) {
                $sub->where('nombre', 'like', "%{$buscar}%")
                    ->orWhere('email', 'like', "%{$buscar}%")
                    ->orWhere('numero_documento', 'like', "%{$buscar}%");
            });
        }
        if ($estado = $request->query('estado')) {
            $q->where('estado', $estado);
        }
        if ($tipo = $request->query('tipo_negocio_id')) {
            $q->where('tipo_negocio_id', $tipo);
        }

        return response()->json(
            $q->orderByDesc('id')->get()->map(fn ($e) => $this->serializar($e))->values()
        );
    }

    /** Actualiza los datos básicos de la empresa. */
    public function update(ActualizarEmpresaRequest $request, Empresa $empresa): JsonResponse
    {
        $data = $request->validated();

        $empresa->update($data);
        Auditoria::registrar($request->user()->id, $empresa->owner_user_id, 'EMPRESA', 'EDITAR', null, $empresa->nombre);

        return response()->json($this->serializar($empresa->fresh(['owner', 'plan', 'tipoNegocio'])));
    }

    /** Cambia el estado de la empresa (bloquea/permite el acceso de todo su equipo). */
    public function cambiarEstado(Request $request, Empresa $empresa): JsonResponse
    {
        $data = $request->validate(['estado' => ['required', 'in:ACTIVO,SUSPENDIDO,DESACTIVADO']]);

        $anterior = $empresa->estado;
        $empresa->update(['estado' => $data['estado'], 'activo' => $data['estado'] === 'ACTIVO']);

        // Activar desde aquí una cuenta pendiente (sin pasar por el código)
        // también debe arrancar la prueba gratis de su dueño; si no, la
        // empresa se queda sin fecha de vencimiento y nunca se bloquea.
        if ($empresa->owner?->estado === 'PENDIENTE_ACTIVACION' && $data['estado'] === 'ACTIVO') {
            $empresa->owner->activarPendiente();
        } else {
            // Sincroniza el estado del dueño y revoca sesiones si queda inactiva.
            $empresa->owner?->update(['estado' => $data['estado'], 'activo' => $data['estado'] === 'ACTIVO']);
        }
        if ($data['estado'] !== 'ACTIVO') {
            foreach ($empresa->usuarios as $u) {
                $u->tokens()->delete();
            }
        }

        Auditoria::registrar($request->user()->id, $empresa->owner_user_id, 'EMPRESA_ESTADO', null, $anterior, $data['estado']);

        return response()->json($this->serializar($empresa->fresh(['owner', 'plan', 'tipoNegocio'])));
    }

    /**
     * Fija manualmente hasta cuándo tiene acceso la empresa (independiente
     * del ciclo mensual estándar de renovación): sirve tanto para extender
     * a un cliente que pagó por fuera de la pasarela, como para el caso real
     * que motivó esto — una empresa con la prueba/membresía vencida seguía
     * bloqueada por VerificarMembresia aunque el super-admin la pusiera en
     * estado ACTIVO, porque el bloqueo se decide por esta fecha, no por el
     * estado. Aplica al "gobernante" del grupo de negocios vinculados (la
     * membresía se comparte entre ellos, igual que el límite de clientes).
     */
    public function cambiarMembresia(Request $request, Empresa $empresa): JsonResponse
    {
        $data = $request->validate([
            'membresia_vence_at' => ['required', 'date'],
        ]);
        $gobernante = $empresa->empresaGobernante();

        $anterior = $gobernante->membresia_vence_at?->toDateString();
        $gobernante->forceFill([
            'membresia_vence_at' => $data['membresia_vence_at'],
            // Si se extiende, que vuelva a avisar al super-admin cuando de
            // verdad venza otra vez (si no, esta bandera ya disparada antes
            // dejaría la nueva fecha vencida en silencio).
            'prueba_alerta_enviada' => false,
        ])->save();
        $gobernante->owner?->forceFill(['membresia_vence_at' => $data['membresia_vence_at']])->save(); // espejo legado

        Auditoria::registrar($request->user()->id, $gobernante->owner_user_id, 'EMPRESA_MEMBRESIA', null, $anterior, $data['membresia_vence_at']);

        return response()->json($this->serializar($empresa->fresh(['owner', 'plan', 'tipoNegocio'])));
    }

    /**
     * El super-admin elige si la empresa sigue en la prueba gratis de 15 días
     * o si ya pasa a membresía/prepago (p. ej. porque le vendió un plan por
     * fuera de la app). Si se saca de 'prueba', el badge "🎁 prueba gratis"
     * del panel desaparece de inmediato y pasa a verse como cualquier otro
     * cliente pago — para eso están los planes. Aplica al gobernante del
     * grupo, igual que el resto de estos ajustes.
     */
    public function cambiarModoCobro(Request $request, Empresa $empresa): JsonResponse
    {
        $data = $request->validate(['modo_cobro' => ['required', 'in:prueba,membresia,prepago']]);
        $gobernante = $empresa->empresaGobernante();

        $anterior = $gobernante->modo_cobro;
        $gobernante->update(['modo_cobro' => $data['modo_cobro']]);
        $gobernante->owner?->update(['modo_cobro' => $data['modo_cobro']]); // espejo legado

        Auditoria::registrar($request->user()->id, $gobernante->owner_user_id, 'EMPRESA_MODO_COBRO', null, $anterior, $data['modo_cobro']);

        return response()->json($this->serializar($empresa->fresh(['owner', 'plan', 'tipoNegocio'])));
    }

    /**
     * Genera un nuevo código de activación de 6 dígitos para el dueño de la
     * empresa (p. ej. si agotó los 5 intentos o perdió el que tenía).
     */
    public function regenerarCodigoActivacion(Request $request, Empresa $empresa): JsonResponse
    {
        $owner = $empresa->owner;
        abort_unless($owner && $owner->estado === 'PENDIENTE_ACTIVACION', 422, 'El dueño de esta empresa ya está activo.');

        $codigo = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $owner->forceFill(['codigo_activacion' => $codigo, 'codigo_activacion_intentos' => 0])->save();

        Auditoria::registrar($request->user()->id, $owner->id, 'EMPRESA', 'REGENERAR_CODIGO_ACTIVACION', null, null);

        // El código nuevo también queda visible en el panel, pero antes se
        // perdía si el super-admin cerraba la pantalla sin copiarlo: ahora
        // también le llega por notificación interna y correo, igual que en
        // el registro inicial, para poder entregárselo al dueño de la empresa.
        $superAdmin = $request->user();
        if ($superAdmin) {
            $notificador = app(Notificador::class);
            $mensaje = "Empresa: {$empresa->nombre}\nDueño: {$owner->name}\nCorreo: {$owner->email}\n\nNuevo código de activación: {$codigo}";
            $notificador->aUsuario($superAdmin->id, 'ADMIN', 'Código de activación regenerado', $mensaje);
            try {
                $notificador->correo(
                    $superAdmin->email,
                    'Código de activación regenerado — Fénix',
                    'Código de activación regenerado',
                    ["Empresa: {$empresa->nombre}", "Dueño: {$owner->name}", "Correo: {$owner->email}", "Nuevo código de activación: {$codigo}"],
                );
            } catch (\Throwable $e) {
                // No bloquear la regeneración si falla el envío de correo.
            }
        }

        return response()->json($this->serializar($empresa->fresh(['owner', 'plan', 'tipoNegocio'])));
    }

    /**
     * Envía por correo, directo al dueño de la empresa, el código de
     * activación que ya tiene asignado (sin regenerarlo). El super-admin
     * sigue siendo quien decide CUÁNDO enviarlo — este botón solo reemplaza
     * el copiar/pegar manual del código a WhatsApp/correo personal.
     */
    public function enviarCodigoActivacion(Request $request, Empresa $empresa): JsonResponse
    {
        $owner = $empresa->owner;
        abort_unless($owner && $owner->estado === 'PENDIENTE_ACTIVACION', 422, 'El dueño de esta empresa ya está activo.');

        $enviado = app(Notificador::class)->correo(
            $owner->email,
            'Tu código de activación — Fénix',
            '¡Ya casi puedes entrar!',
            [
                "Hola {$owner->name},",
                'Tu cuenta en Fénix fue aprobada. Usa este código de 6 dígitos en la pantalla de activación para empezar:',
                $owner->codigo_activacion,
                'Si no solicitaste esta cuenta, puedes ignorar este mensaje.',
            ],
        );

        abort_unless($enviado, 500, 'No se pudo enviar el correo. Revisa la configuración de correo (MAIL_*) e intenta de nuevo.');

        Auditoria::registrar($request->user()->id, $owner->id, 'EMPRESA', 'ENVIAR_CODIGO_ACTIVACION', null, null);

        return response()->json(['message' => "Código enviado a {$owner->email}."]);
    }

    /**
     * Cambia el plan de la empresa. Si pertenece a un grupo de negocios
     * vinculados ("Mis negocios"), el plan se guarda en la empresa
     * GOBERNANTE del grupo (aunque se haya hecho clic en otra fila), porque
     * es la que de verdad sostiene el plan compartido de todo el grupo.
     */
    public function cambiarPlan(Request $request, Empresa $empresa): JsonResponse
    {
        $data = $request->validate(['plan_id' => ['required', 'exists:plans,id']]);
        $gobernante = $empresa->empresaGobernante();

        $anterior = $gobernante->plan?->nombre;
        $gobernante->update(['plan_id' => $data['plan_id']]);
        $gobernante->owner?->update(['plan_id' => $data['plan_id']]); // espejo legado

        Auditoria::registrar($request->user()->id, $gobernante->owner_user_id, 'EMPRESA_PLAN', null, $anterior, $gobernante->fresh('plan')->plan?->nombre);

        return response()->json($this->serializar($empresa->fresh(['owner', 'plan', 'tipoNegocio'])));
    }

    /**
     * Cambia el límite manual de clientes y/o citas (null = usar el del
     * plan). Igual que cambiarPlan(), se aplica sobre la empresa gobernante
     * del grupo para que el ajuste cubra a todos los negocios vinculados.
     */
    public function cambiarLimite(Request $request, Empresa $empresa): JsonResponse
    {
        $data = $request->validate([
            'limite_clientes' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'limite_citas' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);
        $gobernante = $empresa->empresaGobernante();

        if ($request->has('limite_clientes')) {
            $gobernante->update(['limite_clientes' => $data['limite_clientes'] ?? null]);
            $gobernante->owner?->update(['limite_clientes' => $data['limite_clientes'] ?? null]); // espejo legado
            Auditoria::registrar($request->user()->id, $gobernante->owner_user_id, 'EMPRESA_LIMITE', null, null, (string) ($data['limite_clientes'] ?? 'plan'));
        }
        if ($request->has('limite_citas')) {
            $gobernante->update(['limite_citas' => $data['limite_citas'] ?? null]);
            $gobernante->owner?->update(['limite_citas' => $data['limite_citas'] ?? null]); // espejo legado
            Auditoria::registrar($request->user()->id, $gobernante->owner_user_id, 'EMPRESA_LIMITE_CITAS', null, null, (string) ($data['limite_citas'] ?? 'plan'));
        }

        return response()->json($this->serializar($empresa->fresh(['owner', 'plan', 'tipoNegocio'])));
    }

    /** Matriz de módulos de la empresa (estado efectivo + override + origen). */
    public function modulos(Empresa $empresa): JsonResponse
    {
        $overrides = EmpresaModulo::where('empresa_id', $empresa->id)
            ->join('modulos', 'modulos.id', '=', 'empresa_modulos.modulo_id')
            ->pluck('empresa_modulos.estado', 'modulos.clave');

        $defaultsTipo = $empresa->tipoNegocio?->modulos_default;

        $items = [];
        foreach (Funcionalidades::CATALOGO as $clave => $label) {
            $items[] = [
                'clave' => $clave,
                'label' => $label,
                'estado' => Funcionalidades::estadoEfectivoEmpresa($empresa, $clave),
                'override' => $overrides[$clave] ?? null,
                'por_plan' => Funcionalidades::estadoPorPlan($empresa->plan?->nombre, $clave),
                'permitido_por_tipo' => ! is_array($defaultsTipo) || in_array($clave, $defaultsTipo, true),
            ];
        }

        return response()->json([
            'empresa' => [
                'id' => $empresa->id,
                'nombre' => $empresa->nombre,
                'plan' => $empresa->plan?->nombre,
                'tipo_negocio' => $empresa->tipoNegocio?->nombre,
            ],
            'estados' => Funcionalidades::ESTADOS,
            'items' => $items,
        ]);
    }

    /** Guarda el override de un módulo para la empresa. */
    public function guardarModulos(Request $request, Empresa $empresa): JsonResponse
    {
        $data = $request->validate([
            'clave' => ['required', 'string', 'in:' . implode(',', array_keys(Funcionalidades::CATALOGO))],
            'estado' => ['required', 'in:' . implode(',', Funcionalidades::ESTADOS)],
        ]);

        $modulo = Modulo::where('clave', $data['clave'])->firstOrFail();
        $anterior = Funcionalidades::estadoEfectivoEmpresa($empresa, $data['clave']);

        EmpresaModulo::updateOrCreate(
            ['empresa_id' => $empresa->id, 'modulo_id' => $modulo->id],
            ['estado' => $data['estado']],
        );

        Auditoria::registrar($request->user()->id, $empresa->owner_user_id, 'EMPRESA_MODULO', $data['clave'], $anterior, $data['estado']);

        return $this->modulos($empresa->fresh(['plan', 'tipoNegocio']));
    }

    /** Elimina los overrides: la empresa vuelve a los módulos de su plan/tipo de negocio. */
    public function aplicarPlanModulos(Request $request, Empresa $empresa): JsonResponse
    {
        EmpresaModulo::where('empresa_id', $empresa->id)->delete();
        // Limpia también los overrides legados por usuario dueño.
        \App\Models\UserFuncionalidad::where('user_id', $empresa->owner_user_id)->delete();

        Auditoria::registrar($request->user()->id, $empresa->owner_user_id, 'EMPRESA_MODULO', '*', 'override', 'plan:' . ($empresa->plan?->nombre ?? '-'));

        return $this->modulos($empresa->fresh(['plan', 'tipoNegocio']));
    }

    // ===== Catálogo de tipos de negocio =====

    /** CRUD del catálogo de tipos de negocio (solo super-admin). */
    public function tiposNegocio(): JsonResponse
    {
        return response()->json(
            TipoNegocio::orderBy('orden')->get()
        );
    }

    public function guardarTipoNegocio(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'exists:tipos_negocio,id'],
            'clave' => ['required', 'string', 'max:50'],
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'modulos_default' => ['nullable', 'array'],
            'modulos_default.*' => ['string', 'in:' . implode(',', array_keys(Funcionalidades::CATALOGO))],
            'activo' => ['boolean'],
            'orden' => ['nullable', 'integer', 'min:0'],
        ]);

        $tipo = TipoNegocio::updateOrCreate(
            ['clave' => $data['clave']],
            [
                'nombre' => $data['nombre'],
                'descripcion' => $data['descripcion'] ?? null,
                'modulos_default' => $data['modulos_default'] ?? null,
                'activo' => $data['activo'] ?? true,
                'orden' => $data['orden'] ?? 0,
            ]
        );

        Auditoria::registrar($request->user()->id, null, 'TIPO_NEGOCIO', 'GUARDAR', null, $tipo->clave);

        return response()->json($tipo, $tipo->wasRecentlyCreated ? 201 : 200);
    }
}
