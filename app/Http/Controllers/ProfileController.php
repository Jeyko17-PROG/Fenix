<?php

namespace App\Http\Controllers;

use App\Models\Archivo;
use App\Services\CloudinaryUploader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function __construct(private CloudinaryUploader $cloudinary) {}

    /**
     * Actualiza los datos básicos del perfil del usuario autenticado.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'telefono' => ['sometimes', 'nullable', 'string', 'max:50'],
            'tipo_documento' => ['sometimes', 'nullable', 'in:CC,CE,NIT,PAS'],
            'numero_documento' => ['sometimes', 'nullable', 'string', 'max:50'],
            'email' => ['sometimes', 'email', 'unique:users,email,' . $user->id],
        ]);

        $user->update($data);

        return response()->json($user->fresh()->load('rol', 'plan'));
    }

    /**
     * Cambio de contraseña del usuario autenticado (requiere la contraseña actual).
     */
    public function cambiarPassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'password_actual' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        if (! Hash::check($data['password_actual'], $user->password)) {
            throw ValidationException::withMessages([
                'password_actual' => ['La contraseña actual no es correcta.'],
            ]);
        }

        $user->update(['password' => Hash::make($data['password'])]);

        return response()->json(['message' => 'Contraseña actualizada correctamente.']);
    }

    /**
     * Sube/actualiza la foto de perfil.
     * Acepta tanto un archivo del computador como una captura de la cámara (PWA).
     */
    public function uploadFoto(Request $request): JsonResponse
    {
        $request->validate([
            // El frontend comprime la imagen a ~417 KB antes de subirla (misma resolución, menos peso);
            // este límite es solo una red de seguridad por si llega sin comprimir (navegador viejo, API, etc).
            'foto' => ['required', 'image', 'mimes:jpeg,png,jpg,webp', 'max:1024'], // 1 MB
        ]);

        $user = $request->user();
        $file = $request->file('foto');

        try {
            $resultado = $this->cloudinary->subir($file->getRealPath(), "logix/perfiles/user_{$user->id}");
        } catch (\Throwable $e) {
            Log::error('Cloudinary: fallo al subir foto de perfil', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'No se pudo subir la foto a Cloudinary. Intenta de nuevo en un momento.'], 502);
        }

        $url = $resultado['secure_url'];

        // Registra el archivo en la tabla central de archivos.
        $archivo = Archivo::create([
            'nombre_original' => $file->getClientOriginalName(),
            'ruta' => $resultado['public_id'],
            'url' => $url,
            'tipo_mime' => $file->getClientMimeType(),
            'tamano_bytes' => $file->getSize(),
            'subido_por' => $user->id,
        ]);

        $user->update(['foto_perfil_url' => $url]);

        return response()->json([
            'foto_perfil_url' => $url,
            'archivo_id' => $archivo->id,
            'user' => $user->fresh()->load('rol'),
        ]);
    }

    /**
     * Sube/actualiza el logo del negocio (empresa del usuario autenticado).
     * Se muestra en el portal público de reservas y en el QR de reserva.
     */
    public function subirLogoEmpresa(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->esPropietario()) {
            return response()->json(['message' => 'Solo el propietario del negocio puede cambiar el logo.'], 403);
        }

        $empresa = $user->empresaDeCobro();
        if (! $empresa) {
            return response()->json(['message' => 'Tu cuenta aún no tiene una empresa asociada.'], 422);
        }

        $request->validate([
            // El frontend comprime la imagen a ~417 KB antes de subirla (misma resolución, menos peso);
            // este límite es solo una red de seguridad por si llega sin comprimir (navegador viejo, API, etc).
            'logo' => ['required', 'image', 'mimes:jpeg,png,jpg,webp', 'max:1024'], // 1 MB
        ]);

        $file = $request->file('logo');

        try {
            $resultado = $this->cloudinary->subir($file->getRealPath(), "logix/logos/empresa_{$empresa->id}");
        } catch (\Throwable $e) {
            Log::error('Cloudinary: fallo al subir logo de negocio', ['empresa_id' => $empresa->id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'No se pudo subir el logo a Cloudinary. Intenta de nuevo en un momento.'], 502);
        }

        $url = $resultado['secure_url'];

        $archivo = Archivo::create([
            'nombre_original' => $file->getClientOriginalName(),
            'ruta' => $resultado['public_id'],
            'url' => $url,
            'tipo_mime' => $file->getClientMimeType(),
            'tamano_bytes' => $file->getSize(),
            'subido_por' => $user->id,
        ]);

        $empresa->update(['logo_url' => $url]);

        return response()->json([
            'logo_url' => $url,
            'archivo_id' => $archivo->id,
        ]);
    }

    /**
     * Alternativa al logo real: el dueño elige un emoji como "marca" del negocio
     * (ej. 💅 para un spa de uñas). Se usa en el portal público y el QR mientras
     * no haya un logo_url cargado. Enviar logo_emoji vacío lo quita.
     */
    public function actualizarLogoEmoji(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->esPropietario()) {
            return response()->json(['message' => 'Solo el propietario del negocio puede cambiar el logo.'], 403);
        }

        $empresa = $user->empresaDeCobro();
        if (! $empresa) {
            return response()->json(['message' => 'Tu cuenta aún no tiene una empresa asociada.'], 422);
        }

        $data = $request->validate([
            'logo_emoji' => ['nullable', 'string', 'max:20'],
        ]);

        $empresa->update(['logo_emoji' => $data['logo_emoji'] ?? null]);

        return response()->json(['logo_emoji' => $empresa->logo_emoji]);
    }

    /**
     * Políticas (requisitos, abono/cancelación) y enlaces a redes sociales
     * del negocio, visibles en el portal público de reservas. Son identidad
     * de ESTE negocio puntual — nunca se comparten con otros negocios
     * vinculados en "Mis negocios", aunque sí compartan plan.
     */
    public function actualizarPerfilPublico(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->esPropietario()) {
            return response()->json(['message' => 'Solo el propietario del negocio puede editar esto.'], 403);
        }

        $empresa = $user->empresaDeCobro();
        if (! $empresa) {
            return response()->json(['message' => 'Tu cuenta aún no tiene una empresa asociada.'], 422);
        }

        $data = $request->validate([
            'politicas' => ['nullable', 'string', 'max:2000'],
            'instagram_url' => ['nullable', 'string', 'max:255'],
            'tiktok_url' => ['nullable', 'string', 'max:255'],
            'facebook_url' => ['nullable', 'string', 'max:255'],
            'whatsapp_url' => ['nullable', 'string', 'max:255'],
        ]);

        $empresa->update($data);

        return response()->json($empresa->fresh());
    }
}
