<?php

namespace App\Http\Controllers;

use App\Models\MetodoPagoCobro;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Métodos de pago manuales del negocio (Nequi, Daviplata, Bancolombia, link
 * de pago, etc.): el dueño los configura una vez desde Configuración y se
 * muestran al momento de cobrar (QR, número de cuenta/teléfono, enlace) para
 * que el cliente pueda pagar por transferencia sin salir del POS.
 */
class MetodoPagoCobroController extends Controller
{
    public function index()
    {
        return MetodoPagoCobro::orderBy('orden')->orderBy('id')->get();
    }

    public function store(Request $request)
    {
        $metodo = MetodoPagoCobro::create($this->validar($request));

        if ($url = $this->guardarQr($request, $metodo)) {
            $metodo->update(['qr_url' => $url]);
        }

        return response()->json($metodo, 201);
    }

    public function update(Request $request, MetodoPagoCobro $metodoPago)
    {
        $data = $this->validar($request, $metodoPago);

        // Sin archivo nuevo, se conserva el QR actual (el frontend no lo reenvía).
        if ($url = $this->guardarQr($request, $metodoPago)) {
            $data['qr_url'] = $url;
        }

        $metodoPago->update($data);

        return $metodoPago->fresh();
    }

    public function destroy(MetodoPagoCobro $metodoPago)
    {
        if ($metodoPago->qr_url) {
            Storage::disk('public')->delete(\App\Support\StorageUrl::rutaLocal($metodoPago->qr_url));
        }
        $metodoPago->delete();

        return response()->json(['message' => 'Método de pago eliminado.']);
    }

    /**
     * Sube el QR al almacenamiento local de Laravel (disco "public", servido
     * vía storage:link) bajo metodos_pago/empresa_{id}/. Persistente entre
     * despliegues: storage/app/public vive en un volumen Docker dedicado
     * (ver docker-compose.yml, storage_data).
     */
    private function guardarQr(Request $request, MetodoPagoCobro $metodo): ?string
    {
        if (! $request->hasFile('qr_imagen')) {
            return null;
        }

        $request->validate([
            'qr_imagen' => ['image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
        ]);

        // Reemplaza el archivo anterior en vez de dejarlo huérfano en disco.
        if ($metodo->qr_url) {
            Storage::disk('public')->delete(\App\Support\StorageUrl::rutaLocal($metodo->qr_url));
        }

        $path = $request->file('qr_imagen')->store("metodos_pago/empresa_{$metodo->empresa_id}", 'public');

        return Storage::url($path);
    }

    private function validar(Request $request, ?MetodoPagoCobro $metodo = null): array
    {
        return $request->validate([
            'tipo' => ['required', 'string', 'max:50'],
            'nombre' => ['nullable', 'string', 'max:100'],
            'numero_cuenta' => ['nullable', 'string', 'max:50'],
            'enlace' => ['nullable', 'string', 'max:255'],
            'activo' => ['sometimes', 'boolean'],
            'orden' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
