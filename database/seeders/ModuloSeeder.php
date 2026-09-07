<?php

namespace Database\Seeders;

use App\Models\Modulo;
use App\Support\Funcionalidades;
use Illuminate\Database\Seeder;

/**
 * Espejo en BD del catálogo de módulos (Funcionalidades::CATALOGO).
 * Idempotente: updateOrCreate por clave.
 */
class ModuloSeeder extends Seeder
{
    public function run(): void
    {
        $orden = 0;
        foreach (Funcionalidades::CATALOGO as $clave => $nombre) {
            Modulo::updateOrCreate(
                ['clave' => $clave],
                ['nombre' => $nombre, 'activo' => true, 'orden' => $orden++]
            );
        }
    }
}
