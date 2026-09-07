<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Horario laboral y bloqueos de agenda POR SUCURSAL. `bodega_id` nulo sigue
 * significando "aplica a toda la empresa" (retrocompatible con lo ya creado);
 * si una sucursal tiene sus propias filas, esas tienen prioridad sobre el
 * horario general (ver AgendaService::horariosDelDia).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('horarios_laborales', function (Blueprint $table) {
            if (! Schema::hasColumn('horarios_laborales', 'bodega_id')) {
                $table->foreignId('bodega_id')->nullable()->after('owner_id')
                    ->constrained('bodegas')->cascadeOnDelete();
            }
        });

        Schema::table('bloqueos_agenda', function (Blueprint $table) {
            if (! Schema::hasColumn('bloqueos_agenda', 'bodega_id')) {
                $table->foreignId('bodega_id')->nullable()->after('owner_id')
                    ->constrained('bodegas')->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('bloqueos_agenda', function (Blueprint $table) {
            if (Schema::hasColumn('bloqueos_agenda', 'bodega_id')) {
                $table->dropConstrainedForeignId('bodega_id');
            }
        });

        Schema::table('horarios_laborales', function (Blueprint $table) {
            if (Schema::hasColumn('horarios_laborales', 'bodega_id')) {
                $table->dropConstrainedForeignId('bodega_id');
            }
        });
    }
};
