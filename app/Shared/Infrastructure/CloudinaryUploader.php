<?php

namespace App\Shared\Infrastructure;

use Cloudinary\Cloudinary;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CloudinaryUploader
{
    /**
     * Sube un archivo a Cloudinary bajo un public_id fijo, con overwrite: la resubida
     * reemplaza el archivo anterior en el mismo lugar (sin dejar huérfanos que borrar
     * aparte) y el cambio de versión en la URL resultante evita el caché del navegador.
     *
     * Sin CLOUDINARY_URL configurado (ej. recién desplegado, antes de dar de alta la
     * cuenta de Cloudinary), guarda el archivo en el disco local en su lugar — las
     * subidas de imágenes deben funcionar igual, no fallar silenciosamente porque
     * falta una integración externa opcional.
     */
    public function subir(string $rutaTemporal, string $publicId, string $resourceType = 'image'): array
    {
        if (! $this->configurado()) {
            return $this->subirLocal($rutaTemporal, $publicId);
        }

        // uploadApi()->upload() devuelve un Cloudinary\Api\ApiResponse (ArrayObject), no un
        // array; getArrayCopy() extrae los datos reales (secure_url, public_id, etc.) — un
        // cast (array) devolvería las propiedades públicas de la clase, no esos datos.
        return (new Cloudinary())->uploadApi()->upload($rutaTemporal, [
            'public_id' => $publicId,
            'overwrite' => true,
            'invalidate' => true,
            'resource_type' => $resourceType,
        ])->getArrayCopy();
    }

    /** Contraparte de subir(): borra el archivo, en Cloudinary o en disco local según corresponda. */
    public function borrar(string $publicId, string $resourceType = 'image'): void
    {
        if (! $this->configurado()) {
            // No se guarda la extensión aparte del public_id, así que se prueban
            // todas las que subirLocal() puede haber usado.
            foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
                Storage::disk('public')->delete($this->rutaLocal($publicId) . '.' . $ext);
            }
            return;
        }

        (new Cloudinary())->uploadApi()->destroy($publicId, ['resource_type' => $resourceType]);
    }

    private function configurado(): bool
    {
        return (bool) config('services.cloudinary.url');
    }

    /** Mismo public_id que usaría Cloudinary, pero como ruta dentro de storage/app/public. */
    private function rutaLocal(string $publicId): string
    {
        return 'uploads/' . $publicId;
    }

    private function subirLocal(string $rutaTemporal, string $publicId): array
    {
        // La ruta temporal (ej. UploadedFile::getRealPath()) no tiene el nombre ni
        // la extensión reales del archivo subido — hay que detectarla por el
        // contenido (MIME real), no por el nombre del archivo temporal.
        $ruta = $this->rutaLocal($publicId) . '.' . $this->extensionPorContenido($rutaTemporal);

        // Overwrite real: si el mismo public_id ya tenía un archivo con otra
        // extensión, lo quita primero (si no, quedarían ambos y la vieja seguiría
        // siendo accesible por su URL anterior).
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
            $rutaVieja = $this->rutaLocal($publicId) . '.' . $ext;
            if ($rutaVieja !== $ruta) {
                Storage::disk('public')->delete($rutaVieja);
            }
        }

        Storage::disk('public')->put($ruta, file_get_contents($rutaTemporal));

        // version=timestamp replica lo que hace Cloudinary con invalidate:true:
        // evita que el navegador se quede con la imagen vieja al resubir en el
        // mismo public_id.
        $url = Storage::disk('public')->url($ruta) . '?v=' . time();

        return ['secure_url' => $url, 'public_id' => $publicId];
    }

    private function extensionPorContenido(string $rutaTemporal): string
    {
        $mime = @mime_content_type($rutaTemporal) ?: '';

        return match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg', // cubre image/jpeg y cualquier otro caso (los controladores ya validan mimes:jpeg,png,jpg,webp antes)
        };
    }
}
