<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bodegas', function (Blueprint $table) {
            $table->boolean('es_principal')->default(false)->after('activo');
        });

        Schema::table('users', function (Blueprint $table) {
            // Identificador público único del portal de reservas de cada usuario.
            $table->string('reservas_slug')->nullable()->unique()->after('numero_documento');
        });
    }

    public function down(): void
    {
        Schema::table('bodegas', function (Blueprint $table) {
            $table->dropColumn('es_principal');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['reservas_slug']);
            $table->dropColumn('reservas_slug');
        });
    }
};
