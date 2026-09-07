<?php

namespace App\Http\Controllers;

use App\Models\AssetVehicle;
use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AssetVehicleController extends Controller
{
    /**
     * Listar todos los activos/vehículos.
     */
    public function index(Request $request): JsonResponse
    {
        $query = AssetVehicle::with(['cliente:id,nombre_completo']);

        if ($buscar = $request->query('buscar')) {
            $query->where(function ($w) use ($buscar) {
                $w->where('placa_identificador', 'like', "%{$buscar}%")
                    ->orWhere('marca', 'like', "%{$buscar}%")
                    ->orWhere('modelo', 'like', "%{$buscar}%");
            });
        }

        if ($tipo = $request->query('tipo_activo')) {
            $query->where('tipo_activo', $tipo);
        }

        if ($clienteId = $request->query('cliente_id')) {
            $query->where('cliente_id', $clienteId);
        }

        $query->where('activo', true);

        return response()->json($query->orderByDesc('created_at')->paginate(20));
    }

    /**
     * Crear un nuevo activo/vehículo.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validar($request);
        $data['owner_id'] = $request->user()->workspaceOwnerId();
        $activo = AssetVehicle::create($data);
        return response()->json($activo->load('cliente:id,nombre_completo'), 201);
    }

    /**
     * Obtener detalle y hoja de vida del activo.
     */
    public function show(AssetVehicle $assetVehicle): JsonResponse
    {
        return response()->json(
            $assetVehicle->load([
                'cliente:id,nombre_completo',
                'history' => fn ($q) => $q->orderByDesc('fecha_entrada'),
                'serviceOrders' => fn ($q) => $q->orderByDesc('created_at'),
            ])
        );
    }

    /**
     * Hoja de vida completa del activo.
     */
    public function hojaDeVida(AssetVehicle $assetVehicle): JsonResponse
    {
        return response()->json([
            'activo' => $assetVehicle,
            'historia' => $assetVehicle->history()
                ->with('serviceOrder:id,numero_orden,estado,total')
                ->orderByDesc('fecha_entrada')
                ->get(),
        ]);
    }

    /**
     * Actualizar un activo.
     */
    public function update(Request $request, AssetVehicle $assetVehicle): JsonResponse
    {
        $data = $this->validar($request, $assetVehicle->id);
        $assetVehicle->update($data);
        return response()->json($assetVehicle->load('cliente:id,nombre_completo'));
    }

    /**
     * Eliminar (soft delete) un activo.
     */
    public function destroy(AssetVehicle $assetVehicle): JsonResponse
    {
        $assetVehicle->delete();
        return response()->json(['message' => 'Activo eliminado.']);
    }

    /**
     * Buscar por placa/identificador.
     */
    public function buscarPorPlaca(Request $request): JsonResponse
    {
        $placa = $request->query('q');
        if (!$placa) {
            return response()->json([]);
        }

        $resultados = AssetVehicle::where('placa_identificador', 'like', "%{$placa}%")
            ->with('cliente:id,nombre_completo')
            ->limit(10)
            ->get();

        return response()->json($resultados);
    }

    /**
     * Obtener tipos de activos disponibles.
     */
    public function tipos(): JsonResponse
    {
        return response()->json([
            'tipos' => [
                ['valor' => 'moto', 'etiqueta' => 'Motocicleta'],
                ['valor' => 'auto', 'etiqueta' => 'Automóvil'],
                ['valor' => 'celular', 'etiqueta' => 'Celular'],
                ['valor' => 'computadora', 'etiqueta' => 'Computadora'],
                ['valor' => 'electrodomestico', 'etiqueta' => 'Electrodoméstico'],
                ['valor' => 'otro', 'etiqueta' => 'Otro'],
            ],
        ]);
    }

    /**
     * Validar datos del activo.
     */
    private function validar(Request $request, ?int $id = null): array
    {
        $unique = $id ? ",{$id}" : '';
        return $request->validate([
            'cliente_id' => ['nullable', 'exists:clientes,id'],
            'tipo_activo' => ['required', 'string', 'max:50'],
            'placa_identificador' => ['nullable', 'string', 'max:50', "unique:assets_vehicles,placa_identificador{$unique}"],
            'marca' => ['required', 'string', 'max:100'],
            'modelo' => ['required', 'string', 'max:100'],
            'anio' => ['nullable', 'integer', 'min:1900', 'max:' . date('Y')],
            'color' => ['nullable', 'string', 'max:50'],
            'descripcion' => ['nullable', 'string'],
            'notas_tecnicas' => ['nullable', 'string'],
            'activo' => ['boolean'],
        ]);
    }
}
