<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use App\Models\CajaSesion;
use App\Models\Cliente;
use App\Models\Factura;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Multi-caja: apertura y cierre de turno con arqueo.
 *
 * Cada cajero abre su turno con una base en efectivo; al cerrar, el sistema
 * calcula el monto esperado (base + ventas - gastos del turno) y registra el
 * descuadre contra el efectivo contado.
 *
 * Nota: mientras las facturas no registren medio de pago, el esperado asume
 * que todas las ventas del turno fueron en efectivo.
 */
class CajaController extends Controller
{
    /** Historial de turnos de caja (los del workspace; el cajero solo ve los suyos). */
    public function index(Request $request): JsonResponse
    {
        $q = CajaSesion::with('cajero:id,name', 'bodega:id,nombre')->orderByDesc('abierta_at');

        if (! $request->user()->esPropietario()) {
            $q->where('user_id', $request->user()->id);
        }
        if ($estado = $request->query('estado')) {
            $q->where('estado', $estado);
        }

        return response()->json($q->paginate(15));
    }

    /** Turno abierto del usuario actual (o null). */
    public function actual(Request $request): JsonResponse
    {
        $sesion = CajaSesion::where('user_id', $request->user()->id)
            ->where('estado', 'ABIERTA')
            ->latest('abierta_at')
            ->first();

        if (! $sesion) {
            return response()->json(['sesion' => null]);
        }

        return response()->json([
            'sesion' => $sesion->load('bodega:id,nombre'),
            'ventas' => $sesion->totalVentas(),
            'gastos' => $sesion->totalGastos(),
            'esperado' => (float) $sesion->monto_apertura + $sesion->totalVentas() - $sesion->totalGastos(),
            'por_metodo' => $sesion->ventasPorMetodo(),
        ]);
    }

    public function storeIngreso(Request $request): JsonResponse
    {
        $data = $request->validate([
            'descripcion' => ['required', 'string', 'max:255'],
            'monto' => ['required', 'numeric', 'gt:0'],
            'nombre_cliente' => ['required', 'string', 'max:255'],
            'cedula' => ['required', 'string', 'max:50'],
            'fecha' => ['nullable', 'date'],
        ]);

        $sesion = CajaSesion::where('user_id', $request->user()->id)
            ->where('estado', 'ABIERTA')
            ->latest('abierta_at')
            ->first();

        $cliente = Cliente::where('owner_id', $request->user()->workspaceOwnerId())
            ->where(function ($q) use ($data) {
                $q->where('nombre_completo', trim($data['nombre_cliente']))
                    ->orWhere('numero_documento', trim($data['cedula']));
            })
            ->first();

        if (! $cliente) {
            $cliente = Cliente::create([
                'owner_id' => $request->user()->workspaceOwnerId(),
                'empresa_id' => $request->user()->empresaId(),
                'nombre_completo' => trim($data['nombre_cliente']),
                'numero_documento' => trim($data['cedula']),
                'email' => null,
                'telefono' => null,
                'direccion' => null,
                'estado' => 'ACTIVO',
                'created_by' => $request->user()->id,
            ]);
        } else {
            $cliente->forceFill([
                'nombre_completo' => trim($data['nombre_cliente']),
                'numero_documento' => trim($data['cedula']),
            ])->save();
        }

        $monto = round((float) $data['monto'], 2);
        $factura = Factura::create([
            'numero' => $this->siguienteNumero(),
            'bodega_id' => $sesion?->bodega_id ?? $request->user()->bodega_id,
            'cliente_id' => $cliente->id,
            'fecha' => $data['fecha'] ?? now()->toDateString(),
            'subtotal' => $monto,
            'impuestos' => 0,
            'total' => $monto,
            'estado' => 'EMITIDA',
            'notas' => 'Ingreso de caja: ' . $data['descripcion'],
            'created_by' => $request->user()->id,
        ]);

        $factura->detalles()->create([
            'descripcion' => $data['descripcion'],
            'cantidad' => 1,
            'precio_unitario' => $monto,
            'subtotal' => $monto,
            'impuesto' => 0,
            'impuesto_porcentaje' => 0,
        ]);

        Auditoria::registrar($request->user()->id, null, 'FACTURA', 'EMITIR', null, $factura->numero, $factura->bodega_id);

        return response()->json($factura->load(['cliente:id,nombre_completo,email,telefono', 'detalles']), 201);
    }

    /** Abre un turno de caja con la base en efectivo. */
    public function abrir(Request $request): JsonResponse
    {
        $data = $request->validate([
            'monto_apertura' => ['required', 'numeric', 'min:0'],
            'bodega_id' => ['nullable', 'exists:bodegas,id'],
            'notas_apertura' => ['nullable', 'string'],
        ]);

        $abierta = CajaSesion::where('user_id', $request->user()->id)->where('estado', 'ABIERTA')->exists();
        if ($abierta) {
            return response()->json(['message' => 'Ya tienes un turno de caja abierto. Ciérralo antes de abrir otro.'], 422);
        }

        $sesion = CajaSesion::create([
            'user_id' => $request->user()->id,
            'bodega_id' => $data['bodega_id'] ?? $request->user()->bodega_id,
            'estado' => 'ABIERTA',
            'monto_apertura' => $data['monto_apertura'],
            'notas_apertura' => $data['notas_apertura'] ?? null,
            'abierta_at' => now(),
        ]);

        Auditoria::registrar($request->user()->id, null, 'CAJA', 'ABRIR', null, '$' . number_format((float) $data['monto_apertura'], 0), $sesion->bodega_id);

        return response()->json($sesion, 201);
    }

    private function siguienteNumero(): string
    {
        return Factura::siguienteNumero(request()->user()?->empresaId());
    }

    /** Cierra el turno: calcula esperado, registra el conteo y el descuadre. */
    public function cerrar(Request $request, CajaSesion $sesion): JsonResponse
    {
        if ($sesion->estado !== 'ABIERTA') {
            return response()->json(['message' => 'Este turno de caja ya está cerrado.'], 422);
        }
        if ($sesion->user_id !== $request->user()->id && ! $request->user()->esPropietario()) {
            return response()->json(['message' => 'Solo el cajero del turno o el dueño pueden cerrarlo.'], 403);
        }

        $data = $request->validate([
            'monto_cierre' => ['required', 'numeric', 'min:0'], // efectivo contado
            'notas_cierre' => ['nullable', 'string'],
        ]);

        $sesion->cerrada_at = now();
        $ventas = $sesion->totalVentas();
        $gastos = $sesion->totalGastos();
        $esperado = (float) $sesion->monto_apertura + $ventas - $gastos;

        $sesion->fill([
            'estado' => 'CERRADA',
            'monto_esperado' => $esperado,
            'monto_cierre' => $data['monto_cierre'],
            'descuadre' => (float) $data['monto_cierre'] - $esperado, // negativo = faltó dinero
            'notas_cierre' => $data['notas_cierre'] ?? null,
        ])->save();

        Auditoria::registrar($request->user()->id, null, 'CAJA', 'CERRAR', '$' . number_format($esperado, 0), '$' . number_format((float) $data['monto_cierre'], 0), $sesion->bodega_id);

        return response()->json([
            'sesion' => $sesion->fresh(),
            'ventas' => $ventas,
            'gastos' => $gastos,
            'esperado' => $esperado,
            'descuadre' => (float) $sesion->descuadre,
            'por_metodo' => $sesion->ventasPorMetodo(),
        ]);
    }
}
