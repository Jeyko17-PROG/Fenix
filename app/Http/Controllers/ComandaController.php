<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\Comanda;
use App\Models\ComandaItem;
use App\Models\Factura;
use App\Models\Mesa;
use App\Models\Producto;
use App\Services\CreditService;
use App\Services\KardexService;
use App\Services\ReciboService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Comandas del restaurante: el mesero abre la mesa, agrega ítems (que la
 * cocina ve en el KDS) y al final cobra: la comanda se convierte en factura
 * con medio de pago y propina, y la mesa queda libre.
 */
class ComandaController extends Controller
{
    public function __construct(
        private KardexService $kardex,
        private CreditService $creditService,
        private ReciboService $recibo,
    ) {}

    /** Abre una comanda en la mesa (o devuelve la que ya está abierta). */
    public function abrir(Request $request, Mesa $mesa): JsonResponse
    {
        $abierta = $mesa->comandaAbierta()->with('items.producto:id,nombre')->first();
        if ($abierta) {
            return response()->json($abierta);
        }

        $comanda = Comanda::create([
            'mesa_id' => $mesa->id,
            'user_id' => $request->user()->id,
            'estado' => 'ABIERTA',
        ]);
        $mesa->update(['estado' => 'OCUPADA']);

        return response()->json($comanda->load('items'), 201);
    }

    public function show(Comanda $comanda): JsonResponse
    {
        return response()->json(
            $comanda->load('mesa:id,nombre', 'mesero:id,name', 'items.producto:id,nombre')
        );
    }

    /** Agrega un ítem a la comanda (queda PENDIENTE para la cocina). */
    public function agregarItem(Request $request, Comanda $comanda): JsonResponse
    {
        if ($comanda->estado !== 'ABIERTA') {
            return response()->json(['message' => 'La comanda ya fue cobrada o cancelada.'], 422);
        }

        $data = $request->validate([
            'producto_id' => ['nullable', 'exists:productos,id'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'cantidad' => ['required', 'numeric', 'gt:0'],
            'precio_unitario' => ['nullable', 'numeric', 'min:0'],
            'notas' => ['nullable', 'string', 'max:255'],
        ]);

        $producto = ! empty($data['producto_id']) ? Producto::find($data['producto_id']) : null;
        $precio = $data['precio_unitario'] ?? $producto?->precio_venta ?? 0;
        $descripcion = $data['descripcion'] ?? $producto?->nombre;

        if (! $descripcion) {
            return response()->json(['message' => 'Indica el producto o la descripción del ítem.'], 422);
        }

        $item = $comanda->items()->create([
            'producto_id' => $producto?->id,
            'descripcion' => $descripcion,
            'cantidad' => $data['cantidad'],
            'precio_unitario' => $precio,
            'subtotal' => round($data['cantidad'] * $precio, 2),
            'estado_cocina' => 'PENDIENTE',
            'notas' => $data['notas'] ?? null,
        ]);

        return response()->json($item->load('producto:id,nombre'), 201);
    }

    public function quitarItem(Comanda $comanda, ComandaItem $item): JsonResponse
    {
        if ($comanda->estado !== 'ABIERTA') {
            return response()->json(['message' => 'La comanda ya fue cobrada o cancelada.'], 422);
        }
        $item->delete();
        return response()->json(['message' => 'Ítem eliminado.']);
    }

    /** KDS: ítems pendientes/preparando/listos agrupados por comanda (para la cocina). */
    public function cocina(): JsonResponse
    {
        $comandas = Comanda::where('estado', 'ABIERTA')
            ->whereHas('items', fn ($q) => $q->whereIn('estado_cocina', ['PENDIENTE', 'PREPARANDO', 'LISTO']))
            ->with([
                'mesa:id,nombre',
                'mesero:id,name',
                'items' => fn ($q) => $q->whereIn('estado_cocina', ['PENDIENTE', 'PREPARANDO', 'LISTO'])->orderBy('id'),
            ])
            ->orderBy('created_at')
            ->get();

        return response()->json($comandas);
    }

    /** La cocina avanza el estado de un ítem: PENDIENTE→PREPARANDO→LISTO→ENTREGADO. */
    public function estadoItem(Request $request, ComandaItem $item): JsonResponse
    {
        $data = $request->validate(['estado_cocina' => ['required', 'in:PENDIENTE,PREPARANDO,LISTO,ENTREGADO']]);
        $item->update($data);
        return response()->json($item);
    }

    /** Cancela la comanda y libera la mesa (sin factura). */
    public function cancelar(Comanda $comanda): JsonResponse
    {
        if ($comanda->estado !== 'ABIERTA') {
            return response()->json(['message' => 'La comanda ya fue cobrada o cancelada.'], 422);
        }
        $comanda->update(['estado' => 'CANCELADA']);
        $comanda->mesa?->update(['estado' => 'LIBRE']);
        return response()->json(['message' => 'Comanda cancelada y mesa liberada.']);
    }

    /**
     * Cobra la comanda: crea la factura (con medio de pago, propina y mesa),
     * descuenta inventario de los productos con stock, libera la mesa y envía
     * el recibo al correo si hay cliente con email.
     */
    public function cobrar(Request $request, Comanda $comanda): JsonResponse
    {
        if ($comanda->estado !== 'ABIERTA') {
            return response()->json(['message' => 'La comanda ya fue cobrada o cancelada.'], 422);
        }

        $comanda->load('items.producto', 'mesa');
        if ($comanda->items->isEmpty()) {
            return response()->json(['message' => 'La comanda no tiene ítems para cobrar.'], 422);
        }

        $data = $request->validate([
            'metodo_pago' => ['required', 'in:EFECTIVO,TARJETA,TRANSFERENCIA,NEQUI,DAVIPLATA'],
            'propina' => ['nullable', 'numeric', 'min:0'],
            'cliente_id' => ['nullable', 'exists:clientes,id'],
        ]);

        // Cliente: el indicado o el "Consumidor Final" del negocio.
        $clienteId = $data['cliente_id']
            ?? Cliente::where('nombre_completo', 'Consumidor Final')->value('id')
            ?? Cliente::query()->value('id');
        if (! $clienteId) {
            return response()->json(['message' => 'Crea al menos un cliente (ej. "Consumidor Final") para poder facturar.'], 422);
        }

        $factura = DB::transaction(function () use ($data, $request, $comanda, $clienteId) {
            // Pago por uso (modo prepago): cada factura consume 1 crédito.
            $owner = $request->user()->billingOwner();
            if (! $owner->esSuperAdmin() && $owner->modo_cobro === 'prepago') {
                $this->creditService->consume($owner->id, 'facturacion', 1);
            }

            $subtotal = (float) $comanda->items->sum('subtotal');

            $factura = Factura::create([
                'numero' => Factura::siguienteNumero($request->user()->empresaId()),
                'bodega_id' => \App\Models\Bodega::query()->orderByDesc('es_principal')->orderBy('id')->value('id'),
                'cliente_id' => $clienteId,
                'mesa_id' => $comanda->mesa_id,
                'fecha' => now()->toDateString(),
                'subtotal' => $subtotal,
                'impuestos' => 0,
                'total' => $subtotal,
                'estado' => 'EMITIDA',
                'metodo_pago' => $data['metodo_pago'],
                'propina' => $data['propina'] ?? null,
                'notas' => 'Comanda ' . ($comanda->mesa?->nombre ?? "#{$comanda->id}"),
                'created_by' => $request->user()->id,
            ]);

            foreach ($comanda->items as $item) {
                $factura->detalles()->create([
                    'producto_id' => $item->producto_id,
                    'descripcion' => $item->descripcion . ($item->notas ? " ({$item->notas})" : ''),
                    'cantidad' => $item->cantidad,
                    'precio_unitario' => $item->precio_unitario,
                    'impuesto_porcentaje' => 0,
                    'subtotal' => $item->subtotal,
                    'impuesto' => 0,
                ]);

                // Descuenta inventario solo de productos con stock (bebidas, etc.);
                // los platos preparados se marcan como servicio y no descuentan.
                if ($item->producto && ! $item->producto->is_service && $factura->bodega_id) {
                    try {
                        $this->kardex->salida((int) $item->producto_id, (int) $factura->bodega_id,
                            (float) $item->cantidad, $request->user()->id, 'VENTA_RESTAURANTE',
                            ['tipo' => 'COMANDA', 'id' => $comanda->id]);
                    } catch (\Illuminate\Validation\ValidationException) {
                        // Sin stock registrado: no bloquear el cobro del restaurante.
                    }
                }

                $item->update(['estado_cocina' => 'ENTREGADO']);
            }

            $comanda->update(['estado' => 'COBRADA', 'factura_id' => $factura->id]);
            $comanda->mesa?->update(['estado' => 'LIBRE']);

            Auditoria::registrar($request->user()->id, null, 'FACTURA', 'COBRAR_COMANDA',
                $comanda->mesa?->nombre, $factura->numero, $factura->bodega_id);

            return $factura->load(['cliente:id,nombre_completo,email,telefono', 'detalles']);
        });

        // Recibo al correo del cliente (en segundo plano; no revierte el cobro si falla).
        $this->recibo->enviarPorCorreo($factura);

        return response()->json($factura, 201);
    }
}
