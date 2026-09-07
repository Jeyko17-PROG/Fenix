<?php

namespace App\Http\Controllers;

use App\Models\Proveedor;
use Illuminate\Http\Request;

class ProveedorController extends Controller
{
    public function index(Request $request)
    {
        $q = Proveedor::query();
        if ($buscar = $request->query('buscar')) {
            $q->where('razon_social', 'like', "%{$buscar}%")
                ->orWhere('numero_documento', 'like', "%{$buscar}%");
        }
        return $q->orderBy('razon_social')->paginate(20);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $data = $this->validar($request);
        $data['created_by'] = $user->id;
        $data['owner_id'] = $user->workspaceOwnerId();
        $data['empresa_id'] = $user->empresaId();
        return response()->json(Proveedor::create($data), 201);
    }

    public function show(Proveedor $proveedor)
    {
        return $proveedor->load('productos');
    }

    public function update(Request $request, Proveedor $proveedor)
    {
        $user = $request->user();
        $data = $this->validar($request, $proveedor->id);
        $data['owner_id'] = $proveedor->owner_id ?? $user->workspaceOwnerId();
        $data['empresa_id'] = $proveedor->empresa_id ?? $user->empresaId();
        $proveedor->update($data);
        return $proveedor;
    }

    public function destroy(Proveedor $proveedor)
    {
        $proveedor->delete();
        return response()->json(['message' => 'Proveedor eliminado.']);
    }

    private function validar(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'razon_social' => ['required', 'string', 'max:255'],
            'tipo_documento' => ['required', 'in:NIT,CC,CE'],
            'numero_documento' => ['required', 'string', 'max:50'],
            'digito_verificacion' => ['nullable', 'string', 'max:2'],
            'email' => ['nullable', 'email'],
            'telefono' => ['nullable', 'string', 'max:50'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'terminos_pago' => ['nullable', 'string', 'max:100'],
        ]);
    }
}
