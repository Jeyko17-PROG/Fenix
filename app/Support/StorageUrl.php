<?php

namespace App\Support;

/**
 * Convierte una URL pública del disco 'public' (Storage::url() siempre la
 * devuelve absoluta, con APP_URL antepuesto — ver config/filesystems.php)
 * de vuelta a la ruta relativa dentro del disco, para poder usarla con
 * Storage::disk('public')->exists()/get()/path().
 *
 * Un simple str_replace('/storage/', '', $url) se rompe apenas la URL es
 * absoluta (deja pegado el host: "https://dominio.comfacturas/x.pdf"); esto
 * pasa por la ruta real de la URL (parse_url), no por el string completo.
 */
class StorageUrl
{
    public static function rutaLocal(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH) ?: $url;

        return ltrim(str_replace('/storage/', '', $path), '/');
    }
}
