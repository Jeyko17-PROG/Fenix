<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * servicios.imagen era varchar(255): un enlace pegado desde Google Imágenes
 * (con parámetros de tracking) supera fácilmente ese límite y PostgreSQL
 * rechaza el insert ("value too long for type character varying(255)"),
 * a diferencia de MySQL que lo trunca en silencio.
 *
 * Ahora las imágenes se suben como archivo real a Cloudinary (URL corta y
 * estable), pero se amplía la columna a TEXT de todas formas: es la defensa
 * correcta ante cualquier URL larga, sin importar su origen.
 *
 * Se usa SQL nativo por motor (no ->change()) porque cambiar el tipo de una
 * columna con Schema Builder requiere doctrine/dbal, que este proyecto no
 * tiene instalado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE servicios ALTER COLUMN imagen TYPE TEXT');
        } elseif (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE servicios MODIFY imagen TEXT NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE servicios ALTER COLUMN imagen TYPE VARCHAR(255)');
        } elseif (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE servicios MODIFY imagen VARCHAR(255) NULL');
        }
    }
};
