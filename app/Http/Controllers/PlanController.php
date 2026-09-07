<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Support\Funcionalidades;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlanController extends Controller
{
    /** Catálogo de planes (lo consultan el panel admin y el dashboard). */
    public function index(): JsonResponse
    {
        return response()->json(
            Plan::orderBy('orden')->get()
        );
    }

    /** Crear un plan (solo super-admin). */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validar($request);
        return response()->json(Plan::create($data), 201);
    }

    /** Editar un plan (precio, límite, características). */
    public function update(Request $request, Plan $plan): JsonResponse
    {
        $plan->update($this->validar($request, $plan));
        return response()->json($plan);
    }

    private function validar(Request $request, ?Plan $plan = null): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:100', 'unique:plans,nombre' . ($plan ? ",{$plan->id}" : '')],
            'precio_mensual' => ['required', 'integer', 'min:0'],
            'limite_clientes' => ['required', 'integer', 'min:1'],
            'limite_citas' => ['required', 'integer', 'min:1'],
            'incluye' => ['nullable', 'array'],
            'incluye.*' => ['string'],
            // Funcionalidades activadas por el plan (claves del catálogo).
            'funcionalidades' => ['nullable', 'array'],
            'funcionalidades.*' => ['string', Rule::in(array_keys(Funcionalidades::CATALOGO))],
            'activo' => ['boolean'],
            'orden' => ['nullable', 'integer'],
        ]);
    }
}
