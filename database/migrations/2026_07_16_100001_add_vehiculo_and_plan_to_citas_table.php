<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos de vehículo (moto/carro + placa) y plan de lavado para las citas
 * del Lavadero. Nullable: los demás tipos de negocio no los usan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('citas', function (Blueprint $table) {
            if (! Schema::hasColumn('citas', 'tipo_vehiculo')) {
                $table->enum('tipo_vehiculo', ['moto', 'carro'])->nullable()->after('servicio_id');
            }
            if (! Schema::hasColumn('citas', 'placa')) {
                $table->string('placa', 20)->nullable()->after('tipo_vehiculo');
            }
            if (! Schema::hasColumn('citas', 'plan_lavado_id')) {
                $table->foreignId('plan_lavado_id')->nullable()->after('placa')
                    ->constrained('planes_lavado')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('citas', function (Blueprint $table) {
            if (Schema::hasColumn('citas', 'plan_lavado_id')) {
                $table->dropConstrainedForeignId('plan_lavado_id');
            }
            if (Schema::hasColumn('citas', 'placa')) {
                $table->dropColumn('placa');
            }
            if (Schema::hasColumn('citas', 'tipo_vehiculo')) {
                $table->dropColumn('tipo_vehiculo');
            }
        });
    }
};
