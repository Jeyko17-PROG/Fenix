<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use App\Models\CajaSesion;
use App\Models\Gasto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gastos diarios del negocio (arriendo, servicios, papelería...).
 * Se pueden atar al turno de caja abierto para descontarlos del arqueo
 * y calcular la utilidad neta real del día.
 */
class GastoController extends Controller
{
    public const CATEGORIAS = ['arriendo', 'servicios', 'papeleria', 'nomina', 'insumos', 'transporte', 'otros'];

    public function index(Request $request): JsonResponse
    {
        $q = Gasto::with('registradoPor:id,name')->orderByDesc('fecha')->orderByDesc('id');

        if ($desde = $request->query('desde')) {
            $q->whereDate('fecha', '>=', $desde);
        }
        if ($hasta = $request->query('hasta')) {
            $q->whereDate('fecha', '<=', $hasta);
        }
        if ($categoria = $request->query('categoria')) {
            $q->where('categoria', $categoria);
        }

        $total = (clone $q)->sum('monto');

        return response()->json([
            'gastos' => $q->paginate(20),
            'total' => (float) $total,
            'categorias' => self::CATEGORIAS,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'categoria' => ['required', 'in:' . implode(',', self::CATEGORIAS)],
            'descripcion' => ['required', 'string', 'max:255'],
            'monto' => ['required', 'numeric', 'gt:0'],
            'fecha' => ['nullable', 'date'],
        ]);

        // Si el cajero tiene un turno abierto, el gasto queda atado a ese arqueo.
        $sesion = CajaSesion::where('user_id', $request->user()->id)->where('estado', 'ABIERTA')->first();

        $gasto = Gasto::create([
            'user_id' => $request->user()->id,
            'caja_sesion_id' => $sesion?->id,
            'bodega_id' => $sesion?->bodega_id ?? $request->user()->bodega_id,
            'categoria' => $data['categoria'],
            'descripcion' => $data['descripcion'],
            'monto' => $data['monto'],
            'fecha' => $data['fecha'] ?? now()->toDateString(),
        ]);

        Auditoria::registrar($request->user()->id, null, 'GASTO', 'REGISTRAR', null, "{$data['categoria']}: \${$data['monto']}", $gasto->bodega_id);

        return response()->json($gasto, 201);
    }

    public function destroy(Request $request, Gasto $gasto): JsonResponse
    {
        // Solo el dueño puede eliminar gastos (evita borrar rastros del arqueo).
        if (! $request->user()->esPropietario()) {
            return response()->json(['message' => 'Solo el dueño puede eliminar gastos.'], 403);
        }

        $gasto->delete();
        Auditoria::registrar($request->user()->id, null, 'GASTO', 'ELIMINAR', "{$gasto->categoria}: \${$gasto->monto}", null, $gasto->bodega_id);

        return response()->json(['message' => 'Gasto eliminado.']);
    }

    /** Utilidad neta real del día: ventas del día - gastos del día. */
    public function utilidadDia(Request $request): JsonResponse
    {
        $fecha = $request->query('fecha', now()->toDateString());

        // Ni anuladas ni cotizaciones (BORRADOR) sin confirmar son dinero real
        // que entró: contarlas aquí sí generaría el descuadre que este reporte
        // existe justamente para evitar.
        $ventas = (float) \App\Models\Factura::whereDate('created_at', $fecha)->whereNotIn('estado', ['ANULADA', 'BORRADOR'])->sum('total');
        $gastos = (float) Gasto::whereDate('fecha', $fecha)->sum('monto');

        // Desglose por medio de pago del día (reporte de cierre).
        $porMetodo = \App\Models\Factura::whereDate('created_at', $fecha)
            ->whereNotIn('estado', ['ANULADA', 'BORRADOR'])
            ->selectRaw('metodo_pago, SUM(total) as total, COUNT(*) as facturas')
            ->groupBy('metodo_pago')
            ->get()
            ->map(fn ($r) => ['metodo' => $r->metodo_pago ?? 'EFECTIVO', 'total' => (float) $r->total, 'facturas' => (int) $r->facturas]);

        return response()->json([
            'fecha' => $fecha,
            'ventas' => $ventas,
            'gastos' => $gastos,
            'utilidad_neta' => $ventas - $gastos,
            'por_metodo' => $porMetodo,
        ]);
    }
}
