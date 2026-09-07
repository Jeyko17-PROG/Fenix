<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Agotado / Disponible" en el catálogo público: un interruptor manual e
 * independiente del stock (muchos negocios del portal no llevan inventario
 * exacto por bodega para cada artículo exhibido).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('productos', 'disponible')) {
            Schema::table('productos', function (Blueprint $table) {
                $table->boolean('disponible')->default(true)->after('activo');
            });
        }
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn('disponible');
        });
    }
};
