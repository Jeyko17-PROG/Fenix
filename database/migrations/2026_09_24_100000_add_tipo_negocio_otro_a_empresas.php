<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cuando el negocio elige "Otro" en el tipo de negocio, aquí describe a
        // qué se dedica de verdad (médico, tecnológico, un local común...), en
        // vez de quedar como un cajón genérico sin describir.
        Schema::table('empresas', function (Blueprint $table) {
            $table->string('tipo_negocio_otro')->nullable()->after('tipo_negocio_id');
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn('tipo_negocio_otro');
        });
    }
};
