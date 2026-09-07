<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use App\Models\MovimientoInventario;
use App\Models\StockBodega;
use App\Services\KardexService;
use Illuminate\Http\Request;

class InventarioController extends Controller
{
    public function __construct(private KardexService $kardex) {}

    /**
     * Stock actual por producto y bodega.
     */
    public function stock(Request $request)
    {
        // Nota: stock_minimo vive en stock_por_bodega (no en productos).
        $q = StockBodega::with(['producto:id,sku,nombre', 'bodega:id,nombre']);
        if ($request->user()?->estaLimitadoABodega()) {
            $q->where('bodega_id', $request->user()->bodega_id);
        }
        if ($bodega = $request->query('bodega_id')) {
            $q->where('bodega_id', $bodega);
        }
        if ($buscar = $request->query('buscar')) {
            $q->whereHas('producto', function ($sub) use ($buscar) {
                $sub->where('nombre', 'like', "%{$buscar}%")
                    ->orWhere('sku', 'like', "%{$buscar}%")
                    ->orWhere('codigo_barras', 'like', "%{$buscar}%");
            });
        }
        return $q->orderByDesc('cantidad')->paginate(30);
    }

    /**
     * Listado de movimientos (Kardex), opcionalmente filtrado por producto.
     */
    public function movimientos(Request $request)
    {
        $q = MovimientoInventario::with([
            'producto:id,sku,nombre',
            'bodegaOrigen:id,nombre',
            'bodegaDestino:id,nombre',
            'usuario:id,name',
        ]);
        if ($request->user()?->estaLimitadoABodega()) {
            $q->where(function ($sub) use ($request) {
                $sub->where('bodega_origen_id', $request->user()->bodega_id)
                    ->orWhere('bodega_destino_id', $request->user()->bodega_id);
            });
        }
        if ($producto = $request->query('producto_id')) {
            $q->where('producto_id', $producto);
        }
        if ($tipo = $request->query('tipo')) {
            $q->where('tipo', $tipo);
        }
        if ($bodegaId = $request->query('bodega_id')) {
            $q->where(fn ($w) => $w->where('bodega_origen_id', $bodegaId)->orWhere('bodega_destino_id', $bodegaId));
        }
        if ($buscar = $request->query('buscar')) {
            $q->whereHas('producto', function ($sub) use ($buscar) {
                $sub->where('nombre', 'like', "%{$buscar}%")
                    ->orWhere('sku', 'like', "%{$buscar}%")
                    ->orWhere('codigo_barras', 'like', "%{$buscar}%");
            });
        }
        return $q->latest()->paginate(30);
    }

    /**
     * Alertas de reabastecimiento: stock <= stock_minimo (con mínimo > 0).
     */
    public function alertas()
    {
        $q = StockBodega::with(['producto:id,sku,nombre', 'bodega:id,nombre']);
        if (request()->user()?->estaLimitadoABodega()) {
            $q->where('bodega_id', request()->user()->bodega_id);
        }

        return $q
            ->where('stock_minimo', '>', 0)
            ->whereColumn('cantidad', '<=', 'stock_minimo')
            ->orderBy('cantidad')
            ->get();
    }

    /**
     * Define/actualiza el stock mínimo de un producto en una bodega.
     */
    public function definirMinimo(Request $request)
    {
        $data = $request->validate([
            'producto_id' => ['required', 'exists:productos,id'],
            'bodega_id' => ['required', 'exists:bodegas,id'],
            'stock_minimo' => ['required', 'numeric', 'min:0'],
        ]);

        if ($request->user()?->estaLimitadoABodega() && (int) $data['bodega_id'] !== (int) $request->user()->bodega_id) {
            abort(403, 'No tienes acceso a otro establecimiento.');
        }
        $stock = StockBodega::firstOrCreate(
            ['producto_id' => $data['producto_id'], 'bodega_id' => $data['bodega_id']],
            ['cantidad' => 0, 'costo_promedio' => 0]
        );
        $stock->update(['stock_minimo' => $data['stock_minimo']]);

        return $stock;
    }

    /**
     * Registra un movimiento de inventario (entrada / salida / traslado).
     */
    public function registrarMovimiento(Request $request)
    {
        $data = $request->validate([
            'tipo' => ['required', 'in:ENTRADA,SALIDA,TRASLADO'],
            'producto_id' => ['required', 'exists:productos,id'],
            'cantidad' => ['required', 'numeric', 'gt:0'],
            'motivo' => ['nullable', 'string', 'max:50'],
            'costo_unitario' => ['nullable', 'numeric', 'min:0'],
            'bodega_origen_id' => ['nullable', 'exists:bodegas,id'],
            'bodega_destino_id' => ['nullable', 'exists:bodegas,id'],
        ]);

        $userId = $request->user()->id;
        if ($request->user()?->estaLimitadoABodega()) {
            foreach (['bodega_origen_id', 'bodega_destino_id'] as $campo) {
                if (! empty($data[$campo]) && (int) $data[$campo] !== (int) $request->user()->bodega_id) {
                    abort(403, 'No tienes acceso a otro establecimiento.');
                }
            }
        }

        $movimiento = match ($data['tipo']) {
            'ENTRADA' => $this->kardex->entrada(
                $data['producto_id'],
                $this->requerir($data, 'bodega_destino_id', 'la bodega de destino'),
                $data['cantidad'],
                $data['costo_unitario'] ?? 0,
                $userId,
                $data['motivo'] ?? 'COMPRA',
            ),
            'SALIDA' => $this->kardex->salida(
                $data['producto_id'],
                $this->requerir($data, 'bodega_origen_id', 'la bodega de origen'),
                $data['cantidad'],
                $userId,
                $data['motivo'] ?? 'VENTA',
            ),
            'TRASLADO' => $this->kardex->traslado(
                $data['producto_id'],
                $this->requerir($data, 'bodega_origen_id', 'la bodega de origen'),
                $this->requerir($data, 'bodega_destino_id', 'la bodega de destino'),
                $data['cantidad'],
                $userId,
            ),
        };

        return response()->json($movimiento->load(['producto:id,sku,nombre']), 201);
    }

    /**
     * Elimina un movimiento cargado manualmente y revierte su efecto en el
     * stock (ver KardexService::eliminar). No permite borrar movimientos
     * generados automáticamente por otro documento (factura, orden de
     * compra, etc.) ni movimientos fuera de la bodega del usuario limitado.
     */
    public function eliminarMovimiento(Request $request, MovimientoInventario $movimiento)
    {
        if ($request->user()?->estaLimitadoABodega()) {
            $bodegaUsuario = $request->user()->bodega_id;
            $tocaSuBodega = (int) $movimiento->bodega_origen_id === (int) $bodegaUsuario
                || (int) $movimiento->bodega_destino_id === (int) $bodegaUsuario;
            abort_unless($tocaSuBodega, 403, 'No tienes acceso a otro establecimiento.');
        }

        $this->kardex->eliminar($movimiento);

        Auditoria::registrar(
            $request->user()->id,
            null,
            'INVENTARIO_MOVIMIENTO',
            'ELIMINAR',
            "{$movimiento->tipo} · {$movimiento->cantidad}",
            null,
            $movimiento->bodega_origen_id ?? $movimiento->bodega_destino_id,
        );

        return response()->json(['message' => 'Movimiento eliminado y stock ajustado.']);
    }

    /**
     * Elimina una fila de stock (producto × bodega) mal cargada o huérfana
     * (producto ya borrado). Si tenía cantidad, KardexService la deja en
     * cero con un ajuste registrado antes de borrar la fila.
     */
    public function eliminarStock(Request $request, StockBodega $stock)
    {
        if ($request->user()?->estaLimitadoABodega()) {
            abort_unless((int) $stock->bodega_id === (int) $request->user()->bodega_id, 403, 'No tienes acceso a otro establecimiento.');
        }

        $etiqueta = $stock->producto?->nombre ?? "producto #{$stock->producto_id}";
        $this->kardex->eliminarStock($stock, $request->user()->id);

        Auditoria::registrar(
            $request->user()->id,
            null,
            'INVENTARIO_STOCK',
            'ELIMINAR',
            $etiqueta,
            null,
            $stock->bodega_id,
        );

        return response()->json(['message' => 'Registro de stock eliminado.']);
    }

    /**
     * Corrige a mano la cantidad de una fila de stock (producto × bodega).
     * No pisa el valor en silencio: internamente registra un ajuste
     * ENTRADA/SALIDA en el Kardex (ver KardexService::ajustarStock), así
     * queda visible en "Movimientos recientes" igual que cualquier otro
     * movimiento manual.
     */
    public function editarStock(Request $request, StockBodega $stock)
    {
        if ($request->user()?->estaLimitadoABodega()) {
            abort_unless((int) $stock->bodega_id === (int) $request->user()->bodega_id, 403, 'No tienes acceso a otro establecimiento.');
        }

        $data = $request->validate([
            'cantidad' => ['required', 'numeric', 'min:0'],
            'costo_unitario' => ['nullable', 'numeric', 'min:0'],
        ]);

        $etiqueta = $stock->producto?->nombre ?? "producto #{$stock->producto_id}";
        $anterior = (float) $stock->cantidad;

        $movimiento = $this->kardex->ajustarStock(
            $stock->producto_id,
            $stock->bodega_id,
            $data['cantidad'],
            $data['costo_unitario'] ?? null,
            $request->user()->id,
        );

        Auditoria::registrar(
            $request->user()->id,
            null,
            'INVENTARIO_STOCK',
            'EDITAR',
            "{$etiqueta}: {$anterior} → {$data['cantidad']}",
            null,
            $stock->bodega_id,
        );

        return response()->json($movimiento->load(['producto:id,sku,nombre']));
    }

    private function requerir(array $data, string $campo, string $nombre): int
    {
        abort_unless(! empty($data[$campo]), 422, "Debes indicar {$nombre}.");
        return (int) $data[$campo];
    }
}
