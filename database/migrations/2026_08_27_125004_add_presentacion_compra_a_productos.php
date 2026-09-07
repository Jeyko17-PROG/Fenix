<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Presentación de compra": permite cargar entradas de inventario en cajas/
 * paquetes (ej. "CAJA de 12 UND") y que se convierta a la unidad base
 * (unidad_medida) antes de tocar el stock real. El stock y el Kardex SIEMPRE
 * siguen en unidad_medida; esto es solo un multiplicador para la carga de
 * datos, no cambia cómo se guarda ni se calcula el inventario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->string('unidad_compra', 20)->nullable()->after('unidad_medida');
            $table->decimal('unidades_por_compra', 14, 4)->nullable()->after('unidad_compra');
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn(['unidad_compra', 'unidades_por_compra']);
        });
    }
};
