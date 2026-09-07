<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multisucursal: `bodegas` ya modela "una ubicación física de la empresa"
 * (nombre, dirección, responsable, activo). Le agregamos teléfono/ciudad
 * en vez de crear una tabla `sucursales` paralela que duplicaría el concepto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bodegas', function (Blueprint $table) {
            if (! Schema::hasColumn('bodegas', 'telefono')) {
                $table->string('telefono')->nullable()->after('direccion');
            }
            if (! Schema::hasColumn('bodegas', 'ciudad')) {
                $table->string('ciudad')->nullable()->after('telefono');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bodegas', function (Blueprint $table) {
            if (Schema::hasColumn('bodegas', 'ciudad')) {
                $table->dropColumn('ciudad');
            }
            if (Schema::hasColumn('bodegas', 'telefono')) {
                $table->dropColumn('telefono');
            }
        });
    }
};
