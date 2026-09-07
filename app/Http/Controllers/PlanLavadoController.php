<?php

namespace App\Http\Controllers;

use App\Models\PlanLavado;
use Illuminate\Http\Request;

class PlanLavadoController extends Controller
{
    public function index()
    {
        return PlanLavado::orderBy('orden')->orderBy('nombre')->get();
    }

    public function store(Request $request)
    {
        return response()->json(PlanLavado::create($this->validar($request)), 201);
    }

    public function update(Request $request, PlanLavado $planLavado)
    {
        $planLavado->update($this->validar($request));
        return $planLavado;
    }

    public function destroy(PlanLavado $planLavado)
    {
        $planLavado->delete();
        return response()->json(['message' => 'Plan de lavado eliminado.']);
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'precio' => ['nullable', 'numeric', 'min:0'],
            'duracion_min' => ['required', 'integer', 'min:5'],
            'aplica_moto' => ['boolean'],
            'aplica_carro' => ['boolean'],
            'icono' => ['nullable', 'string', 'max:10'],
            'orden' => ['nullable', 'integer', 'min:0'],
            'activo' => ['boolean'],
        ]);
    }
}
