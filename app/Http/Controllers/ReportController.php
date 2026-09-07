<?php

namespace App\Http\Controllers;

use App\Models\CommissionLiquidation;
use App\Models\OperablesEmployee;
use App\Models\ServiceOrderDetail;
use App\Models\AssetHistory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    /**
     * Reporte de liquidación de comisiones por empleado.
     */
    public function liquidacionesComisiones(Request $request): JsonResponse
    {
        $request->validate([
            'fecha_inicio' => ['nullable', 'date'],
            'fecha_fin' => ['nullable', 'date'],
            'operables_employee_id' => ['nullable', 'exists:operables_employees,id'],
            'estado' => ['nullable', 'in:pendiente,pagada,cancelada'],
        ]);

        $query = CommissionLiquidation::with([
            'operablesEmployee:id,nombre,apellido,ci_cedula',
        ]);

        if ($fechaInicio = $request->query('fecha_inicio')) {
            $query->where('fecha_inicio', '>=', $fechaInicio);
        }

        if ($fechaFin = $request->query('fecha_fin')) {
            $query->where('fecha_fin', '<=', $fechaFin);
        }

        if ($estado = $request->query('estado')) {
            $query->where('estado', $estado);
        }

        if ($empleadoId = $request->query('operables_employee_id')) {
            $query->where('operables_employee_id', $empleadoId);
        }

        $liquidaciones = $query->orderByDesc('created_at')->paginate(20);

        return response()->json([
            'liquidaciones' => $liquidaciones,
            'resumen' => [
                'total_pendiente' => CommissionLiquidation::where('estado', 'pendiente')->sum('monto_total'),
                'total_pagadas' => CommissionLiquidation::where('estado', 'pagada')->sum('monto_total'),
            ],
        ]);
    }

    /**
     * Marcar liquidación como pagada.
     */
    public function marcarPagada(Request $request, CommissionLiquidation $liquidacion): JsonResponse
    {
        $data = $request->validate([
            'referencia_pago' => ['nullable', 'string', 'max:100'],
        ]);

        $liquidacion->marcarPagada($data['referencia_pago'] ?? null);

        return response()->json($liquidacion);
    }

    /**
     * Generar liquidación para un rango de fechas.
     */
    public function generarLiquidacion(Request $request): JsonResponse
    {
        $data = $request->validate([
            'operables_employee_id' => ['required', 'exists:operables_employees,id'],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['required', 'date'],
        ]);

        $liquidacion = CommissionLiquidation::generarLiquidacion(
            auth()->id(),
            $data['operables_employee_id'],
            $data['fecha_inicio'],
            $data['fecha_fin']
        );

        if (!$liquidacion) {
            return response()->json(['error' => 'No hay comisiones para liquidar en este período.'], 422);
        }

        return response()->json($liquidacion, 201);
    }

    /**
     * Hoja de vida / Historial clínico de un activo.
     */
    public function hojaDeVidaActivo(Request $request): JsonResponse
    {
        $request->validate([
            'asset_vehicle_id' => ['required', 'exists:assets_vehicles,id'],
        ]);

        $assetId = $request->query('asset_vehicle_id');
        $historia = AssetHistory::where('asset_vehicle_id', $assetId)
            ->with([
                'serviceOrder:id,numero_orden,estado,total,total_comisiones',
            ])
            ->orderByDesc('fecha_entrada')
            ->paginate(20);

        return response()->json([
            'historia' => $historia,
            'estadisticas' => [
                'total_trabajos' => AssetHistory::where('asset_vehicle_id', $assetId)->count(),
                'costo_total' => AssetHistory::where('asset_vehicle_id', $assetId)->sum('costo_total'),
                'km_total_registrado' => AssetHistory::where('asset_vehicle_id', $assetId)
                    ->where('km_entrada', '!=', null)
                    ->where('km_salida', '!=', null)
                    ->selectRaw('SUM(km_salida - km_entrada) as km_total')
                    ->first()?->km_total ?? 0,
            ],
        ]);
    }

    /**
     * Reporte detallado de comisiones por empleado en un período.
     */
    public function comisionesPorEmpleado(Request $request): JsonResponse
    {
        $request->validate([
            'operables_employee_id' => ['required', 'exists:operables_employees,id'],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['required', 'date'],
        ]);

        $empleadoId = $request->query('operables_employee_id');
        $fechaInicio = $request->query('fecha_inicio');
        $fechaFin = $request->query('fecha_fin');

        $detalles = ServiceOrderDetail::where('operables_employee_id', $empleadoId)
            ->whereBetween('created_at', [$fechaInicio, $fechaFin])
            ->with([
                'serviceOrder:id,numero_orden,cliente_id',
                'producto:id,nombre',
            ])
            ->orderByDesc('created_at')
            ->paginate(20);

        $total = ServiceOrderDetail::where('operables_employee_id', $empleadoId)
            ->whereBetween('created_at', [$fechaInicio, $fechaFin])
            ->sum('comision_aplicada');

        return response()->json([
            'detalles' => $detalles,
            'resumen' => [
                'total_comisiones' => $total,
                'cantidad_trabajos' => ServiceOrderDetail::where('operables_employee_id', $empleadoId)
                    ->whereBetween('created_at', [$fechaInicio, $fechaFin])
                    ->count(),
                'comision_promedio' => $detalles->count() > 0 ? $total / $detalles->count() : 0,
            ],
        ]);
    }
}
