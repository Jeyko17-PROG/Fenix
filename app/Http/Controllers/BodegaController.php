<?php

namespace App\Http\Controllers;

use App\Models\Bodega;
use App\Models\StockBodega;
use Illuminate\Http\Request;

class BodegaController extends Controller
{
    public function index(Request $request)
    {
        $q = Bodega::with('responsable:id,name');
        if ($request->user()?->estaLimitadoABodega()) {
            $q->where('id', $request->user()->bodega_id);
        }

        return $q
            ->orderByDesc('es_principal')->orderBy('nombre')->get();
    }

    public function store(Request $request)
    {
        $data = $this->validar($request);

        // La primera bodega del usuario queda como principal automáticamente.
        if (Bodega::count() === 0) {
            $data['es_principal'] = true;
        }

        return response()->json(Bodega::create($data), 201);
    }

    public function update(Request $request, Bodega $bodega)
    {
        $this->autorizarBodega($request, $bodega);
        $bodega->update($this->validar($request));
        return $bodega;
    }

    public function destroy(Request $request, Bodega $bodega)
    {
        $this->autorizarBodega($request, $bodega);
        // Solo se pueden eliminar bodegas vacías (sin existencias).
        $tieneStock = StockBodega::where('bodega_id', $bodega->id)->where('cantidad', '>', 0)->exists();
        if ($tieneStock) {
            return response()->json(['message' => 'No se puede eliminar una bodega con existencias. Traslada el inventario primero.'], 422);
        }

        $bodega->delete();
        return response()->json(['message' => 'Bodega eliminada.']);
    }

    /** Define esta bodega como la principal del usuario (las demás dejan de serlo). */
    public function definirPrincipal(Request $request, Bodega $bodega)
    {
        $this->autorizarBodega($request, $bodega);
        Bodega::where('id', '!=', $bodega->id)->update(['es_principal' => false]);
        $bodega->update(['es_principal' => true]);

        return response()->json($bodega);
    }

    /** Servicios que ofrece esta sucursal (para el selector del panel y el portal público). */
    public function servicios(Request $request, Bodega $bodega)
    {
        $this->autorizarBodega($request, $bodega);
        return $bodega->servicios()->get(['servicios.id', 'servicios.nombre']);
    }

    /** Asigna (reemplaza) el conjunto de servicios que ofrece esta sucursal. */
    public function sincronizarServicios(Request $request, Bodega $bodega)
    {
        $this->autorizarBodega($request, $bodega);
        $data = $request->validate([
            'servicio_ids' => ['present', 'array'],
            'servicio_ids.*' => ['integer', 'exists:servicios,id'],
        ]);

        $bodega->servicios()->sync($data['servicio_ids']);
        return response()->json($bodega->servicios()->get(['servicios.id', 'servicios.nombre']));
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:50'],
            'ciudad' => ['nullable', 'string', 'max:100'],
            'responsable_id' => ['nullable', 'exists:users,id'],
            'activo' => ['boolean'],
        ]);
    }

    private function autorizarBodega(Request $request, Bodega $bodega): void
    {
        if ($request->user()?->estaLimitadoABodega() && (int) $request->user()->bodega_id !== (int) $bodega->id) {
            abort(403, 'No tienes acceso a otro establecimiento.');
        }
    }
}
