<?php

namespace App\Http\Controllers;

use App\Models\Nota;
use Illuminate\Http\Request;

class NotaController extends Controller
{
    public function index(Request $request)
    {
        $q = Nota::with('cliente:id,nombre_completo');
        if ($cliente = $request->query('cliente_id')) {
            $q->where('cliente_id', $cliente);
        }
        return $q->latest()->get();
    }

    public function store(Request $request)
    {
        $data = $this->validar($request);
        $data['created_by'] = $request->user()->id;
        return response()->json(Nota::create($data), 201);
    }

    public function update(Request $request, Nota $nota)
    {
        $nota->update($this->validar($request));
        return $nota;
    }

    public function destroy(Nota $nota)
    {
        $nota->delete();
        return response()->json(['message' => 'Nota eliminada.']);
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'titulo' => ['nullable', 'string', 'max:255'],
            'contenido' => ['nullable', 'string'],
            'cliente_id' => ['nullable', 'exists:clientes,id'],
        ]);
    }
}
