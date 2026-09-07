<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Foto de referencia que el cliente elige al reservar (ej. en una
     * barbería, el corte de cabello que le gustó de la galería del servicio).
     */
    public function up(): void
    {
        Schema::table('citas', function (Blueprint $table) {
            $table->string('imagen_referencia_url')->nullable()->after('observaciones');
        });
    }

    public function down(): void
    {
        Schema::table('citas', function (Blueprint $table) {
            $table->dropColumn('imagen_referencia_url');
        });
    }
};
