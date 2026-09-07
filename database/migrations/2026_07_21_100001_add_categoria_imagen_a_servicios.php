<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogación de servicios (Spa/Estética, Barbería, etc.): reutiliza la
 * tabla genérica `categorias` (ya usada por Producto) en vez de crear una
 * tabla de categorías exclusiva para servicios.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servicios', function (Blueprint $table) {
            if (! Schema::hasColumn('servicios', 'categoria_id')) {
                $table->foreignId('categoria_id')->nullable()->after('id')
                    ->constrained('categorias')->nullOnDelete();
            }
            if (! Schema::hasColumn('servicios', 'imagen')) {
                $table->string('imagen')->nullable()->after('descripcion');
            }
        });
    }

    public function down(): void
    {
        Schema::table('servicios', function (Blueprint $table) {
            if (Schema::hasColumn('servicios', 'imagen')) {
                $table->dropColumn('imagen');
            }
            if (Schema::hasColumn('servicios', 'categoria_id')) {
                $table->dropConstrainedForeignId('categoria_id');
            }
        });
    }
};
