<?php

namespace App\Http\Controllers;

use App\Models\Adjunto;
use App\Models\Cliente;
use App\Models\Proveedor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Gestión documental genérica (adjuntos) para proveedores y clientes:
 * subir, listar, descargar, reemplazar y eliminar archivos.
 */
class AdjuntoController extends Controller
{
    /** Tipos de entidad permitidos -> clase del modelo. */
    private const TIPOS = [
        'proveedor' => Proveedor::class,
        'cliente' => Cliente::class,
    ];

    private const MIMES = 'pdf,doc,docx,xls,xlsx,jpg,jpeg,png,webp';

    /** Lista los adjuntos de una entidad. GET /adjuntos?tipo=proveedor&id=5 */
    public function index(Request $request)
    {
        $data = $request->validate([
            'tipo' => ['required', 'in:' . implode(',', array_keys(self::TIPOS))],
            'id' => ['required', 'integer'],
        ]);

        $this->verificarEntidad($data['tipo'], $data['id']);

        return Adjunto::where('adjuntable_tipo', self::TIPOS[$data['tipo']])
            ->where('adjuntable_id', $data['id'])
            ->latest()
            ->get();
    }

    /** Sube un archivo nuevo. POST /adjuntos (multipart) */
    public function store(Request $request)
    {
        $data = $request->validate([
            'tipo' => ['required', 'in:' . implode(',', array_keys(self::TIPOS))],
            'id' => ['required', 'integer'],
            'categoria' => ['nullable', 'string', 'max:100'],
            'archivo' => ['required', 'file', 'max:10240', 'mimes:' . self::MIMES],
        ]);

        $this->verificarEntidad($data['tipo'], $data['id']);

        $adjunto = $this->guardarArchivo(
            $request->file('archivo'),
            self::TIPOS[$data['tipo']],
            $data['id'],
            $data['categoria'] ?? null,
            $request->user()->id,
        );

        return response()->json($adjunto, 201);
    }

    /** Reemplaza el archivo de un adjunto existente. POST /adjuntos/{adjunto}/reemplazar */
    public function reemplazar(Request $request, Adjunto $adjunto)
    {
        $data = $request->validate([
            'archivo' => ['required', 'file', 'max:10240', 'mimes:' . self::MIMES],
        ]);

        // Borra el archivo anterior del disco.
        Storage::disk('public')->delete($adjunto->ruta);

        $file = $request->file('archivo');
        $ruta = $this->rutaPara($adjunto->adjuntable_tipo, $adjunto->adjuntable_id, $file);
        Storage::disk('public')->putFileAs(dirname($ruta), $file, basename($ruta));

        $adjunto->update([
            'nombre' => $file->getClientOriginalName(),
            'ruta' => $ruta,
            'url' => Storage::url($ruta),
            'tipo_mime' => $file->getClientMimeType(),
            'tamano_bytes' => $file->getSize(),
        ]);

        return $adjunto->fresh();
    }

    /** Elimina un adjunto (archivo + registro). DELETE /adjuntos/{adjunto} */
    public function destroy(Adjunto $adjunto)
    {
        Storage::disk('public')->delete($adjunto->ruta);
        $adjunto->delete();

        return response()->noContent();
    }

    // --- Helpers ---

    private function guardarArchivo($file, string $tipoClase, int $id, ?string $categoria, int $userId): Adjunto
    {
        $ruta = $this->rutaPara($tipoClase, $id, $file);
        Storage::disk('public')->putFileAs(dirname($ruta), $file, basename($ruta));

        return Adjunto::create([
            'adjuntable_tipo' => $tipoClase,
            'adjuntable_id' => $id,
            'categoria' => $categoria,
            'nombre' => $file->getClientOriginalName(),
            'ruta' => $ruta,
            'url' => Storage::url($ruta),
            'tipo_mime' => $file->getClientMimeType(),
            'tamano_bytes' => $file->getSize(),
            'created_by' => $userId,
        ]);
    }

    private function rutaPara(string $tipoClase, int $id, $file): string
    {
        $corto = Str::lower(class_basename($tipoClase));
        $ext = $file->getClientOriginalExtension() ?: 'bin';
        return "adjuntos/{$corto}/{$id}/" . Str::uuid() . '.' . $ext;
    }

    /** Verifica que la entidad exista dentro del workspace del usuario (OwnerScope). */
    private function verificarEntidad(string $tipo, int $id): void
    {
        $clase = self::TIPOS[$tipo];
        if (! $clase::query()->whereKey($id)->exists()) {
            throw ValidationException::withMessages(['id' => 'La entidad no existe o no pertenece a tu cuenta.']);
        }
    }
}
