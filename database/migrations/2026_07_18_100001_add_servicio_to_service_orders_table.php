<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El POS de barbería (service_orders) necesita saber qué servicio/corte
 * eligió el cliente. A diferencia del lavadero, la barbería NO necesita
 * campos propios de plan (aplica_moto/aplica_carro), así que reutiliza la
 * tabla genérica `servicios` (la misma que ya usa la Agenda de citas).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('service_orders', 'servicio_id')) {
            Schema::table('service_orders', function (Blueprint $table) {
                $table->foreignId('servicio_id')->nullable()->after('plan_lavado_id')
                    ->constrained('servicios')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('service_orders', 'servicio_id')) {
            Schema::table('service_orders', function (Blueprint $table) {
                $table->dropConstrainedForeignId('servicio_id');
            });
        }
    }
};
