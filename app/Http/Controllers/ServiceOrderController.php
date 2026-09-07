<?php

namespace App\Http\Controllers;

use App\Models\Bodega;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderDetail;
use App\Models\Producto;
use App\Models\OperablesEmployee;
use App\Models\AssetHistory;
use App\Services\KardexService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ServiceOrderController extends Controller
{
    public function __construct(private KardexService $kardex) {}

    /**
     * Bodega donde se descuentan los repuestos de las órdenes:
     * la del empleado (si está limitado a una) o la bodega principal.
     */
    private function resolverBodega(Request $request): int
    {
        $user = $request->user();
        if ($user->estaLimitadoABodega()) {
            return (int) $user->bodega_id;
        }

        $principal = Bodega::query()->orderByDesc('es_principal')->orderBy('id')->value('id');
        if (! $principal) {
            throw ValidationException::withMessages([
                'bodega' => ['Debes crear una bodega antes de usar repuestos en las órdenes.'],
            ]);
        }
        return (int) $principal;
    }

    /**
     * Listar órdenes de servicio.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ServiceOrder::with([
            'cliente:id,nombre_completo',
            'assetVehicle:id,placa_identificador,marca,modelo,tipo_activo',
            'planLavado:id,nombre,icono',
            'servicio:id,nombre',
            'mecanicoAsignado:id,nombre,apellido',
            'details.operablesEmployee:id,nombre,apellido',
            'details.producto:id,nombre,precio_venta',
        ]);

        // Rol Mecanico: solo ve las órdenes que tiene asignadas.
        $this->limitarAMecanico($request, $query);

        if ($estado = $request->query('estado')) {
            $query->where('estado', $estado);
        }

        if ($clienteId = $request->query('cliente_id')) {
            $query->where('cliente_id', $clienteId);
        }

        if ($buscar = $request->query('buscar')) {
            $query->where(function ($w) use ($buscar) {
                $w->where('numero_orden', 'like', "%{$buscar}%")
                    ->orWhere('descripcion_trabajo', 'like', "%{$buscar}%")
                    ->orWhereHas('cliente', fn (Builder $q) => $q->where('nombre_completo', 'like', "%{$buscar}%"));
            });
        }

        return response()->json($query->orderByDesc('created_at')->paginate(15));
    }

    /**
     * Crear una nueva orden de servicio.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validar($request);
        $ownerId = $request->user()->workspaceOwnerId();
        $data['owner_id'] = $ownerId;
        $data['estado'] = 'recibido';
        $data['fecha_recepcion'] = now();
        $data['numero_orden'] = ServiceOrder::generarNumeroOrden($ownerId);

        $orden = ServiceOrder::create($data);
        return response()->json($orden->load('cliente:id,nombre_completo', 'assetVehicle:id,placa_identificador,marca,modelo,tipo_activo', 'planLavado:id,nombre,icono', 'servicio:id,nombre', 'mecanicoAsignado:id,nombre,apellido'), 201);
    }

    /**
     * Obtener detalle de una orden.
     */
    public function show(ServiceOrder $serviceOrder): JsonResponse
    {
        $this->autorizarMecanico($serviceOrder);

        return response()->json(
            $serviceOrder->load([
                'cliente:id,nombre_completo,telefono,email',
                'mecanicoAsignado:id,nombre,apellido,ci_cedula',
                'assetVehicle:id,placa_identificador,marca,modelo,anio,color,tipo_activo',
                'planLavado:id,nombre,precio,icono',
                'servicio:id,nombre,precio',
                'details' => fn ($q) => $q->with([
                    'producto:id,nombre,precio_venta,is_service',
                    'operablesEmployee:id,nombre,apellido,ci_cedula',
                ]),
            ])
        );
    }

    /**
     * Actualizar una orden de servicio.
     */
    public function update(Request $request, ServiceOrder $serviceOrder): JsonResponse
    {
        // Permitir cambios solo si no está facturada
        if ($serviceOrder->estado === 'facturado') {
            return response()->json(['error' => 'No se puede editar una orden facturada.'], 403);
        }

        $this->autorizarMecanico($serviceOrder);

        $data = $request->validate([
            'descripcion_trabajo' => ['nullable', 'string'],
            // 'secando' es el paso intermedio del Kanban de lavadero.
            'estado' => ['in:recibido,en_proceso,secando,listo,facturado,cancelado'],
            'operables_employee_id' => ['nullable', 'exists:operables_employees,id'],
            'checklist_entrada' => ['nullable', 'array'],
            'checklist_entrada.*.item' => ['required_with:checklist_entrada', 'string'],
            'checklist_entrada.*.ok' => ['nullable', 'boolean'],
            'fecha_entrega_estimada' => ['nullable', 'date'],
            'requiere_pago_anticipo' => ['boolean'],
            'monto_anticipo' => ['nullable', 'numeric', 'min:0'],
        ]);

        // El mecánico solo registra diagnóstico/avance: no toca anticipos ni reasigna la orden.
        if ($this->esOperarioLimitado($request->user())) {
            $data = array_intersect_key($data, array_flip(['descripcion_trabajo', 'estado', 'checklist_entrada']));
        }

        $serviceOrder->update($data);
        return response()->json($serviceOrder->load('details'));
    }

    /**
     * Agregar un detalle a la orden y descontar del inventario si es repuesto.
     */
    public function agregarDetalle(Request $request, ServiceOrder $serviceOrder): JsonResponse
    {
        $this->autorizarMecanico($serviceOrder);

        $data = $request->validate([
            'producto_id' => ['required', 'exists:productos,id'],
            'operables_employee_id' => ['nullable', 'exists:operables_employees,id'],
            'cantidad' => ['required', 'integer', 'min:1'],
            'precio_unitario' => ['required', 'numeric', 'min:0'],
            'tiene_comision' => ['boolean'],
            'tipo_comision' => ['nullable', 'in:percentage,fixed'],
            'comision_value' => ['nullable', 'numeric', 'min:0'],
            'notas' => ['nullable', 'string'],
        ]);

        // --- INVENTARIO AUTOMÁTICO (Kardex real: stock_por_bodega) ---
        $producto = Producto::find($data['producto_id']);

        // El mecánico no puede fijar precios ni comisiones: se usa el precio de lista del producto.
        if ($this->esOperarioLimitado($request->user())) {
            $data['precio_unitario'] = (float) ($producto?->precio_venta ?? 0);
            unset($data['tiene_comision'], $data['tipo_comision'], $data['comision_value']);
            $data['tiene_comision'] = false;
        }

        // Solo descuenta si no está configurado como servicio/mano de obra.
        // KardexService valida el stock disponible y lanza 422 si no alcanza.
        if ($producto && ! $producto->is_service) {
            $this->kardex->salida(
                (int) $producto->id,
                $this->resolverBodega($request),
                (float) $data['cantidad'],
                $request->user()->id,
                'ORDEN_SERVICIO',
                ['tipo' => 'ORDEN_SERVICIO', 'id' => $serviceOrder->id],
            );
        }
        // -------------------------------------------------------------

        $subtotal = $data['cantidad'] * $data['precio_unitario'];
        $data['subtotal'] = $subtotal;

        // Crear el detalle
        $detail = $serviceOrder->details()->create($data);

        // Calcular comisión si aplica
        if (! empty($data['tiene_comision'])) {
            $detail->calcularComision();
            $detail->save();
        }

        // Recalcular totales de la orden
        $serviceOrder->recalculateTotals();

        return response()->json($detail->load('producto:id,nombre', 'operablesEmployee:id,nombre,apellido'), 201);
    }

    /**
     * Actualizar un detalle de la orden.
     */
    public function actualizarDetalle(Request $request, ServiceOrder $serviceOrder, ServiceOrderDetail $detail): JsonResponse
    {
        $this->autorizarMecanico($serviceOrder);

        $data = $request->validate([
            'cantidad' => ['integer', 'min:1'],
            'precio_unitario' => ['numeric', 'min:0'],
            'tiene_comision' => ['boolean'],
            'tipo_comision' => ['nullable', 'in:percentage,fixed'],
            'comision_value' => ['nullable', 'numeric', 'min:0'],
        ]);

        // El mecánico solo puede ajustar cantidades, nunca precios ni comisiones.
        if ($this->esOperarioLimitado($request->user())) {
            $data = array_intersect_key($data, array_flip(['cantidad']));
        }

        // Ajustar inventario (Kardex) si cambia la cantidad del repuesto
        if (isset($data['cantidad'])) {
            $producto = Producto::find($detail->producto_id);
            if ($producto && ! $producto->is_service) {
                $bodegaId = $this->resolverBodega($request);
                $diferencia = (float) $data['cantidad'] - (float) $detail->cantidad;
                if ($diferencia > 0) {
                    // Kardex valida el disponible y responde 422 si no alcanza.
                    $this->kardex->salida((int) $producto->id, $bodegaId, $diferencia, $request->user()->id,
                        'ORDEN_SERVICIO', ['tipo' => 'ORDEN_SERVICIO', 'id' => $serviceOrder->id]);
                } elseif ($diferencia < 0) {
                    $this->kardex->entrada((int) $producto->id, $bodegaId, abs($diferencia),
                        $this->costoPromedioActual($producto->id, $bodegaId), $request->user()->id,
                        'DEVOLUCION_ORDEN', ['tipo' => 'ORDEN_SERVICIO', 'id' => $serviceOrder->id]);
                }
            }
        }

        if (isset($data['cantidad']) && isset($data['precio_unitario'])) {
            $data['subtotal'] = $data['cantidad'] * $data['precio_unitario'];
        }

        $detail->update($data);

        // Recalcular comisión si cambió
        if (isset($data['tiene_comision']) || isset($data['tipo_comision']) || isset($data['comision_value'])) {
            $detail->calcularComision();
            $detail->save();
        }

        $serviceOrder->recalculateTotals();

        return response()->json($detail);
    }

    /**
     * Eliminar un detalle y devolver el stock al inventario.
     */
    public function eliminarDetalle(Request $request, ServiceOrder $serviceOrder, ServiceOrderDetail $detail): JsonResponse
    {
        // --- RESTABLECER INVENTARIO (devolución en el Kardex) ---
        $producto = Producto::find($detail->producto_id);
        if ($producto && ! $producto->is_service && (float) $detail->cantidad > 0) {
            $bodegaId = $this->resolverBodega($request);
            $this->kardex->entrada((int) $producto->id, $bodegaId, (float) $detail->cantidad,
                $this->costoPromedioActual($producto->id, $bodegaId), $request->user()->id,
                'DEVOLUCION_ORDEN', ['tipo' => 'ORDEN_SERVICIO', 'id' => $serviceOrder->id]);
        }
        // ---------------------------------------------------------

        $detail->delete();
        $serviceOrder->recalculateTotals();
        return response()->json(['message' => 'Detalle eliminado y stock devuelto a inventario.']);
    }

    /** Costo promedio vigente del producto en la bodega (para devoluciones sin alterar el promedio). */
    private function costoPromedioActual(int $productoId, int $bodegaId): float
    {
        return (float) (\App\Models\StockBodega::where('producto_id', $productoId)
            ->where('bodega_id', $bodegaId)->value('costo_promedio') ?? 0);
    }

    /**
     * Preparar para facturación (cambiar estado a "listo").
     */
    public function prepararFacturacion(ServiceOrder $serviceOrder): JsonResponse
    {
        if (empty($serviceOrder->details)) {
            return response()->json(['error' => 'La orden debe tener al menos un detalle.'], 422);
        }

        $serviceOrder->update(['estado' => 'listo']);
        return response()->json($serviceOrder);
    }

    /**
     * Marcar como completada y registrar hoja de vida de la moto de forma automática.
     */
    public function completar(Request $request, ServiceOrder $serviceOrder): JsonResponse
    {
        $data = $request->validate([
            'km_salida' => ['nullable', 'integer', 'min:0'],
            'estado_salida' => ['nullable', 'string', 'max:255'],
        ]);

        // Registrar en hoja de vida (AssetHistory) automáticamente si hay vehículo asociado
        if ($serviceOrder->asset_vehicle_id) {
            AssetHistory::create([
                'asset_vehicle_id' => $serviceOrder->asset_vehicle_id,
                'service_order_id' => $serviceOrder->id,
                'descripcion_trabajo' => $serviceOrder->descripcion_trabajo,
                'costo_total' => $serviceOrder->total,
                'km_salida' => $data['km_salida'] ?? null,
                'estado_salida' => $data['estado_salida'] ?? null,
                'fecha_entrada' => $serviceOrder->fecha_recepcion,
                'fecha_salida' => now(),
            ]);
        }

        $serviceOrder->update([
            'estado' => 'listo',
            'fecha_entrega_real' => now(),
        ]);

        return response()->json($serviceOrder);
    }

    /**
     * Buscar órdenes o activos para precarga rápida en POS.
     */
    public function buscarPorPlacaOOrden(Request $request): JsonResponse
    {
        $query = $request->query('q');
        if (!$query) {
            return response()->json([]);
        }

        // Buscar órdenes de servicio: por número de orden O por la placa del vehículo
        // (la caja cobra buscando la placa: lavadero, taller, barbería).
        $ordenes = ServiceOrder::where('estado', '!=', 'facturado')
            ->where(function ($w) use ($query) {
                $w->where('numero_orden', 'like', "%{$query}%")
                    ->orWhereHas('assetVehicle', fn ($v) => $v->where('placa_identificador', 'like', "%{$query}%"));
            })
            ->with('assetVehicle:id,placa_identificador,marca,modelo', 'cliente:id,nombre_completo')
            ->limit(5)
            ->get();

        // Buscar por placa de activo
        $activos = \App\Models\AssetVehicle::where('placa_identificador', 'like', "%{$query}%")
            ->with('cliente:id,nombre_completo')
            ->limit(5)
            ->get();

        return response()->json([
            'ordenes' => $ordenes,
            'activos' => $activos,
        ]);
    }

    /**
     * Obtener estados disponibles. El lavadero usa un Kanban con "Secando"
     * intermedio; los demás negocios usan el flujo estándar de 4 pasos.
     */
    public function estados(Request $request): JsonResponse
    {
        $esLavadero = $this->tipoNegocio($request) === 'lavadero';

        $estados = $esLavadero
            ? [
                ['valor' => 'recibido', 'etiqueta' => 'En espera'],
                ['valor' => 'en_proceso', 'etiqueta' => 'Lavando'],
                ['valor' => 'secando', 'etiqueta' => 'Secando'],
                ['valor' => 'listo', 'etiqueta' => 'Listo'],
            ]
            : [
                ['valor' => 'recibido', 'etiqueta' => 'Recibido'],
                ['valor' => 'en_proceso', 'etiqueta' => 'En Proceso'],
                ['valor' => 'listo', 'etiqueta' => 'Listo'],
            ];

        $estados[] = ['valor' => 'facturado', 'etiqueta' => 'Facturado'];
        $estados[] = ['valor' => 'cancelado', 'etiqueta' => 'Cancelado'];

        return response()->json(['estados' => $estados]);
    }

    /** Clave del tipo de negocio de la empresa del usuario autenticado. */
    private function tipoNegocio(Request $request): ?string
    {
        return $request->user()?->empresaDeCobro()?->tipoNegocio?->clave;
    }

    /**
     * Validar datos de la orden. Los campos vehiculares son obligatorios solo
     * para negocios que trabajan con vehículos/equipos (talleres, lavadero);
     * en tienda/restaurante/barbería no se piden.
     */
    private function validar(Request $request): array
    {
        $conVehiculo = in_array($this->tipoNegocio($request), ['taller_motos', 'taller_carros', 'taller_general', 'lavadero'], true);

        return $request->validate([
            'cliente_id' => ['required', 'exists:clientes,id'],
            'asset_vehicle_id' => [$conVehiculo ? 'required' : 'nullable', 'exists:assets_vehicles,id'],
            'plan_lavado_id' => ['nullable', 'exists:planes_lavado,id'],
            'servicio_id' => ['nullable', 'exists:servicios,id'],
            'operables_employee_id' => ['nullable', 'exists:operables_employees,id'],
            'descripcion_trabajo' => ['nullable', 'string'],
            // Talleres/lavadero: estado de entrada del vehículo. Servicio técnico: accesorios recibidos.
            'km_entrada' => ['nullable', 'integer', 'min:0'],
            'nivel_gasolina' => ['nullable', 'integer', 'min:0', 'max:100'],
            'accesorios' => ['nullable', 'string', 'max:255'],
            'checklist_entrada' => ['nullable', 'array'],
            'checklist_entrada.*.item' => ['required_with:checklist_entrada', 'string'],
            'checklist_entrada.*.ok' => ['nullable', 'boolean'],
            'fecha_entrega_estimada' => ['nullable', 'date'],
            'requiere_pago_anticipo' => ['boolean'],
            'monto_anticipo' => ['nullable', 'numeric', 'min:0'],
        ]);
    }

    /**
     * Si el usuario tiene rol Mecanico o Lavador, limita la consulta a las
     * órdenes asignadas a su ficha de empleado (a nivel de orden o de detalle).
     */
    private function limitarAMecanico(Request $request, Builder $query): void
    {
        $user = $request->user();
        if (! $this->esOperarioLimitado($user)) {
            return;
        }

        $empleadoIds = OperablesEmployee::where('user_id', $user->id)->pluck('id');

        $query->where(function (Builder $q) use ($empleadoIds) {
            $q->whereIn('operables_employee_id', $empleadoIds)
                ->orWhereHas('details', fn (Builder $d) => $d->whereIn('operables_employee_id', $empleadoIds));
        });
    }

    /** Aborta con 403 si un Mecanico/Lavador intenta acceder a una orden que no tiene asignada. */
    private function autorizarMecanico(ServiceOrder $serviceOrder): void
    {
        $user = request()->user();
        if (! $this->esOperarioLimitado($user)) {
            return;
        }

        $empleadoIds = OperablesEmployee::where('user_id', $user->id)->pluck('id');

        $asignada = $empleadoIds->contains($serviceOrder->operables_employee_id)
            || $serviceOrder->details()->whereIn('operables_employee_id', $empleadoIds)->exists();

        if (! $asignada) {
            abort(403, 'Solo puedes ver las órdenes que tienes asignadas.');
        }
    }

    /** El rol Mecanico (talleres) y el rol Lavador (lavaderos) son ambos "operarios limitados": ven y editan solo lo suyo. */
    private function esOperarioLimitado(?\App\Models\User $user): bool
    {
        return (bool) ($user?->esMecanico() || $user?->esLavador());
    }
}