<?php

namespace App\Http\Controllers;

use App\Models\Archivo;
use App\Models\Servicio;
use App\Services\CloudinaryUploader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ServicioController extends Controller
{
    public function __construct(private CloudinaryUploader $cloudinary) {}

    public function index(Request $request)
    {
        $bodegaId = $request->query('bodega_id');

        return Servicio::with('categoria:id,nombre', 'galeria')
            ->when($bodegaId, fn ($q) => $q->where(
                fn ($w) => $w->whereDoesntHave('bodegas')->orWhereHas('bodegas', fn ($b) => $b->where('bodegas.id', $bodegaId))
            ))
            ->orderBy('nombre')->get();
    }

    public function store(Request $request)
    {
        // El servicio necesita existir primero: la imagen se sube bajo un
        // public_id basado en su id, para que futuras resubidas la reemplacen.
        $servicio = Servicio::create($this->validar($request));

        if ($url = $this->guardarImagen($request, $servicio)) {
            $servicio->update(['imagen' => $url]);
        }

        return response()->json($servicio->load('categoria:id,nombre'), 201);
    }

    public function update(Request $request, Servicio $servicio)
    {
        $data = $this->validar($request, $servicio);

        // overwrite:true en Cloudinary ya reemplaza la imagen anterior en el
        // mismo public_id; sin archivo nuevo, se conserva la imagen actual.
        if ($nueva = $this->guardarImagen($request, $servicio)) {
            $data['imagen'] = $nueva;
        }

        $servicio->update($data);
        return $servicio->load('categoria:id,nombre');
    }

    public function destroy(Servicio $servicio)
    {
        $servicio->delete();
        return response()->json(['message' => 'Servicio eliminado.']);
    }

    /**
     * Sube la foto del servicio a Cloudinary (si viene un archivo) y la
     * registra en archivos. Si Cloudinary falla, no bloquea el guardado del
     * servicio — solo se queda sin imagen y se registra el error en el log.
     */
    private function guardarImagen(Request $request, Servicio $servicio): ?string
    {
        if (! $request->hasFile('imagen')) {
            return null;
        }
        $request->validate([
            'imagen' => ['image', 'mimes:jpeg,png,jpg,webp', 'max:5120'],
        ]);
        $file = $request->file('imagen');

        try {
            $resultado = $this->cloudinary->subir($file->getRealPath(), "logix/servicios/servicio_{$servicio->id}");
        } catch (\Throwable $e) {
            Log::error('Cloudinary: fallo al subir imagen de servicio', ['servicio_id' => $servicio->id, 'error' => $e->getMessage()]);
            return null;
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

        return $url;
    }

    /**
     * $servicio: al editar, se pasa el registro actual para conservar su
     * duración si el catálogo simplificado (barbería/tatuajes) no la envía;
     * al crear (null), una recién creada sin duración cae en 30 min por
     * defecto para que la agenda siga pudiendo calcular horarios.
     */
    private function validar(Request $request, ?Servicio $servicio = null): array
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'categoria_id' => ['nullable', 'exists:categorias,id'],
            // 'imagen' NO se valida aquí como string: cuando viene como
            // archivo la maneja guardarImagen(); si el frontend reenvía la
            // URL actual al editar, ese campo suelto se ignora sin problema.
            'icono' => ['nullable', 'string', 'max:20'],
            // Opcional: el catálogo simplificado (barbería/tatuajes) no la pide;
            // se usa una duración por defecto para que la agenda siga funcionando.
            'duracion_min' => ['nullable', 'integer', 'min:5'],
            'precio' => ['nullable', 'numeric', 'min:0'],
            'activo' => ['boolean'],
        ]);

        if (! array_key_exists('duracion_min', $data) || $data['duracion_min'] === null) {
            $data['duracion_min'] = $servicio?->duracion_min ?? 30;
        }

        return $data;
    }
}
