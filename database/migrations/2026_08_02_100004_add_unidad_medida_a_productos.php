<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            // UND, KG, LT, CAJA, PAR, DOCENA, etc. Se usa también como parte del
            // SKU autogenerado cuando el usuario no escribe uno manualmente.
            $table->string('unidad_medida', 20)->default('UND')->after('descripcion');
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn('unidad_medida');
        });
    }
};
