<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Datos propios de una cita de tatuaje: zona del cuerpo y tamaño aproximado. */
    public function up(): void
    {
        Schema::table('citas', function (Blueprint $table) {
            $table->string('zona_cuerpo', 100)->nullable()->after('placa');
            $table->string('tamano_tatuaje', 50)->nullable()->after('zona_cuerpo');
        });
    }

    public function down(): void
    {
        Schema::table('citas', function (Blueprint $table) {
            $table->dropColumn(['zona_cuerpo', 'tamano_tatuaje']);
        });
    }
};
