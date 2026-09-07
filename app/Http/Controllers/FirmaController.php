<?php

namespace App\Http\Controllers;

use App\Models\Documento;
use App\Models\FirmaElectronica;
use App\Support\StorageUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Gestión de firma electrónica.
 *
 * NOTA: Deja la estructura y los estados (PENDIENTE/FIRMADO/RECHAZADO) listos
 * para conectar en el corto plazo con un Proveedor Tecnológico autorizado DIAN
 * (Colombia). Hoy la validación se simula localmente.
 */
class FirmaController extends Controller
{
    /**
     * Inicia el proceso de firma de un documento (estado PENDIENTE).
     * Calcula el hash del archivo para garantizar integridad.
     */
    public function iniciar(Documento $documento)
    {
        $hash = null;
        if ($documento->archivo_url) {
            $ruta = StorageUrl::rutaLocal($documento->archivo_url);
            if (Storage::disk('public')->exists($ruta)) {
                $hash = hash('sha256', Storage::disk('public')->get($ruta));
            }
        }

        $firma = FirmaElectronica::updateOrCreate(
            ['documento_id' => $documento->id],
            [
                'estado' => 'PENDIENTE',
                'hash_documento' => $hash,
                'proveedor_firma' => 'DIAN (pendiente de integración)',
            ],
        );

        return response()->json($firma, 201);
    }

    /**
     * Actualiza el estado de la firma (FIRMADO o RECHAZADO).
     */
    public function actualizarEstado(Request $request, FirmaElectronica $firma)
    {
        $data = $request->validate([
            'estado' => ['required', 'in:FIRMADO,RECHAZADO'],
        ]);

        $firma->update([
            'estado' => $data['estado'],
            'firmante_id' => $request->user()->id,
            'fecha_firma' => $data['estado'] === 'FIRMADO' ? now() : null,
            'payload_respuesta' => [
                'simulado' => true,
                'estado' => $data['estado'],
                'fecha' => now()->toIso8601String(),
            ],
        ]);

        return $firma->fresh();
    }
}
