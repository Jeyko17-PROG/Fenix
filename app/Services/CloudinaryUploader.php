<?php

namespace App\Services;

use Cloudinary\Cloudinary;

class CloudinaryUploader
{
    /**
     * Sube un archivo a Cloudinary bajo un public_id fijo, con overwrite: la resubida
     * reemplaza el archivo anterior en el mismo lugar (sin dejar huérfanos que borrar
     * aparte) y el cambio de versión en la URL resultante evita el caché del navegador.
     */
    public function subir(string $rutaTemporal, string $publicId, string $resourceType = 'image'): array
    {
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
}
