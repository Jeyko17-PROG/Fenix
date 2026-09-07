<?php

namespace App\Http\Controllers;

use App\Models\OrdenCompra;
use App\Services\KardexService;
use App\Services\Notificador;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrdenCompraController extends Controller
{
    public function __construct(private KardexService $kardex, private Notificador $notificador) {}

    public function index()
    {
        return OrdenCompra::with(['proveedor:id,razon_social', 'bodega:id,nombre'])
            ->withCount('detalles')
            ->latest()
            ->paginate(20);
    }

    public function show(OrdenCompra $orden)
    {
        return $orden->load(['proveedor', 'bodega:id,nombre', 'usuario:id,name', 'detalles.producto:id,sku,nombre']);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'proveedor_id' => ['required', 'exists:proveedores,id'],
            'bodega_id' => ['required', 'exists:bodegas,id'],
            'fecha' => ['required', 'date'],
            'lineas' => ['required', 'array', 'min:1'],
            'lineas.*.producto_id' => ['required', 'exists:productos,id'],
            'lineas.*.cantidad' => ['required', 'numeric', 'gt:0'],
            'lineas.*.precio_unitario' => ['required', 'numeric', 'min:0'],
        ]);

        return DB::transaction(function () use ($data, $request) {
            $total = collect($data['lineas'])->sum(fn ($l) => $l['cantidad'] * $l['precio_unitario']);

            $orden = OrdenCompra::create([
                'proveedor_id' => $data['proveedor_id'],
                'bodega_id' => $data['bodega_id'],
                'usuario_id' => $request->user()->id,
                'fecha' => $data['fecha'],
                'total' => $total,
                'estado' => 'BORRADOR',
            ]);

            foreach ($data['lineas'] as $l) {
                $orden->detalles()->create($l);
            }

            return response()->json($orden->load('detalles.producto:id,sku,nombre'), 201);
        });
    }

    /**
     * Marca la orden como RECIBIDA y genera las entradas de inventario (Kardex).
     */
    public function recibir(OrdenCompra $orden)
    {
        if ($orden->estado === 'RECIBIDA') {
            throw ValidationException::withMessages(['estado' => ['La orden ya fue recibida.']]);
        }

        $alertas = [];

        DB::transaction(function () use ($orden, &$alertas) {
            foreach ($orden->detalles as $detalle) {
                $this->kardex->entrada(
                    $detalle->producto_id,
                    $orden->bodega_id,
                    (float) $detalle->cantidad,
                    (float) $detalle->precio_unitario,
                    $orden->usuario_id,
                    'COMPRA',
                    ['tipo' => OrdenCompra::class, 'id' => $orden->id],
                );

                // Si el precio pagado en esta compra supera el precio de venta
                // vigente del producto, la empresa estaría vendiendo con pérdida
                // a menos que actualice el precio: se avisa al dueño del negocio.
                $producto = $detalle->producto()->first(['id', 'nombre', 'precio_venta']);
                if ($producto && $producto->precio_venta > 0 && (float) $detalle->precio_unitario > (float) $producto->precio_venta) {
                    $alertas[] = [
                        'producto' => $producto->nombre,
                        'precio_compra' => (float) $detalle->precio_unitario,
                        'precio_venta' => (float) $producto->precio_venta,
                    ];
                }
            }
            $orden->update(['estado' => 'RECIBIDA']);
        });

        if ($alertas) {
            $lineas = array_map(
                fn ($a) => "{$a['producto']}: comprado a \${$a['precio_compra']}, pero se vende a \${$a['precio_venta']} (pérdida de \$" . number_format($a['precio_compra'] - $a['precio_venta'], 2) . ' por unidad)',
                $alertas
            );
            $mensaje = "Orden de compra #{$orden->id}:\n" . implode("\n", $lineas);
            $this->notificador->aUsuario(
                $orden->owner_id,
                'COMPRA_MAS_CARA',
                'Compraste más caro de lo que vendes',
                $mensaje,
            );
        }

        return $orden->fresh()->load('detalles.producto:id,sku,nombre');
    }
}
