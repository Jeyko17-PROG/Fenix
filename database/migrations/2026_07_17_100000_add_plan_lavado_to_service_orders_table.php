<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El POS de lavadero (service_orders) necesita saber qué plan de lavado
 * eligió el cliente, igual que ya lo tiene la agenda de citas (Cita.plan_lavado_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('service_orders', 'plan_lavado_id')) {
            Schema::table('service_orders', function (Blueprint $table) {
                $table->foreignId('plan_lavado_id')->nullable()->after('asset_vehicle_id')
                    ->constrained('planes_lavado')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('service_orders', 'plan_lavado_id')) {
            Schema::table('service_orders', function (Blueprint $table) {
                $table->dropConstrainedForeignId('plan_lavado_id');
            });
        }
    }
};
