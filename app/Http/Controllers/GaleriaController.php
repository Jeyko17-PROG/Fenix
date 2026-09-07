<?php

namespace App\Http\Controllers;

use App\Models\Archivo;
use App\Models\GaleriaImagen;
use App\Models\OperablesEmployee;
use App\Models\Producto;
use App\Models\Servicio;
use App\Services\CloudinaryUploader;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Galería de varias fotos por producto o servicio (ej. una barbería subiendo
 * varios cortes de referencia). Comparte la subida a Cloudinary con
 * ProductoController/ServicioController, pero cada imagen queda como un
 * registro aparte en `galeria_imagenes` en vez de reemplazar una sola URL.
 */
class GaleriaController extends Controller
{
    public function __construct(private CloudinaryUploader $cloudinary) {}

    public function subirProducto(Request $request, Producto $producto)
    {
        return $this->subir($request, $producto, "logix/productos/producto_{$producto->id}/galeria");
    }

    public function eliminarProducto(Producto $producto, GaleriaImagen $imagen)
    {
        return $this->eliminar($producto, $imagen);
    }

    public function subirServicio(Request $request, Servicio $servicio)
    {
        return $this->subir($request, $servicio, "logix/servicios/servicio_{$servicio->id}/galeria");
    }

    public function eliminarServicio(Servicio $servicio, GaleriaImagen $imagen)
    {
        return $this->eliminar($servicio, $imagen);
    }

    /** Portafolio de un artista/operario (ej. trabajos previos de un tatuador). */
    public function subirArtista(Request $request, OperablesEmployee $operablesEmployee)
    {
        return $this->subir($request, $operablesEmployee, "logix/artistas/artista_{$operablesEmployee->id}/galeria");
    }

    public function eliminarArtista(OperablesEmployee $operablesEmployee, GaleriaImagen $imagen)
    {
        return $this->eliminar($operablesEmployee, $imagen);
    }

    private function subir(Request $request, Model $imageable, string $carpeta)
    {
        $request->validate([
            'imagen' => ['required', 'image', 'mimes:jpeg,png,jpg,webp', 'max:5120'],
        ]);
        $file = $request->file('imagen');
        $publicId = $carpeta . '/' . Str::random(12);

        try {
            $resultado = $this->cloudinary->subir($file->getRealPath(), $publicId);
        } catch (\Throwable $e) {
            Log::error('Cloudinary: fallo al subir foto de galería', ['imageable' => get_class($imageable), 'id' => $imageable->id, 'error' => $e->getMessage()]);
            abort(502, 'No se pudo subir la imagen. Intenta de nuevo.');
        }

        $url = $resultado['secure_url'];

        Archivo::create([
            'nombre_original' => $file->getClientOriginalName(),
            'ruta' => $resultado['public_id'],
            'url' => $url,
            'tipo_mime' => $file->getClientMimeType(),
            'tamano_bytes' => $file->getSize(),
            'subido_por' => $request->user()->id,
        ]);

        $orden = (int) $imageable->galeria()->max('orden') + 1;

        $item = $imageable->galeria()->create([
            'url' => $url,
            'public_id' => $resultado['public_id'],
            'orden' => $orden,
            'created_by' => $request->user()->id,
        ]);

        return response()->json($item, 201);
    }

    private function eliminar(Model $imageable, GaleriaImagen $imagen)
    {
        abort_unless(
            $imagen->imageable_type === get_class($imageable) && (int) $imagen->imageable_id === (int) $imageable->id,
            404
        );

        // Best-effort: no bloquea el borrado del registro si Cloudinary falla.
        if ($imagen->public_id) {
            try {
                (new \Cloudinary\Cloudinary())->uploadApi()->destroy($imagen->public_id);
            } catch (\Throwable $e) {
                Log::warning('Cloudinary: fallo al borrar foto de galería', ['public_id' => $imagen->public_id, 'error' => $e->getMessage()]);
            }
        }

        $imagen->delete();

        return response()->json(['message' => 'Imagen eliminada.']);
    }
}
