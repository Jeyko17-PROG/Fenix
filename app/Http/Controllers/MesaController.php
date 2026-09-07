<?php

namespace App\Http\Controllers;

use App\Models\Mesa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Plano de mesas del restaurante: LIBRE | OCUPADA | RESERVADA. */
class MesaController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Mesa::with(['comandaAbierta' => fn ($q) => $q->withSum('items as total', 'subtotal')->withCount('items')])
                ->orderBy('orden')->orderBy('id')
                ->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:100'],
            'capacidad' => ['nullable', 'integer', 'min:1', 'max:100'],
            'orden' => ['nullable', 'integer', 'min:0'],
        ]);

        $mesa = Mesa::create([
            'nombre' => $data['nombre'],
            'capacidad' => $data['capacidad'] ?? 4,
            'orden' => $data['orden'] ?? 0,
            'estado' => 'LIBRE',
        ]);

        return response()->json($mesa, 201);
    }

    public function update(Request $request, Mesa $mesa): JsonResponse
    {
        $data = $request->validate([
            'nombre' => ['sometimes', 'string', 'max:100'],
            'capacidad' => ['nullable', 'integer', 'min:1', 'max:100'],
            'orden' => ['nullable', 'integer', 'min:0'],
            'estado' => ['sometimes', 'in:LIBRE,OCUPADA,RESERVADA'],
        ]);

        // No se puede marcar LIBRE una mesa con comanda abierta (primero cobrar/cancelar).
        if (($data['estado'] ?? null) === 'LIBRE' && $mesa->comandaAbierta()->exists()) {
            return response()->json(['message' => 'La mesa tiene una comanda abierta: cóbrala o cancélala primero.'], 422);
        }

        $mesa->update($data);
        return response()->json($mesa->fresh());
    }

    public function destroy(Mesa $mesa): JsonResponse
    {
        if ($mesa->comandaAbierta()->exists()) {
            return response()->json(['message' => 'La mesa tiene una comanda abierta.'], 422);
        }
        $mesa->delete();
        return response()->json(['message' => 'Mesa eliminada.']);
    }
}
