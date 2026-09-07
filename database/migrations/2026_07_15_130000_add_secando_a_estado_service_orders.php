<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Amplía el ENUM de service_orders.estado para incluir 'secando' (Kanban del
 * lavadero: En espera → Lavando → Secando → Listo). En PostgreSQL la columna
 * ya es un varchar/string sin restricción, así que no requiere cambios ahí.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE service_orders MODIFY estado ENUM('recibido','en_proceso','secando','listo','facturado','cancelado') NOT NULL DEFAULT 'recibido'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE service_orders MODIFY estado ENUM('recibido','en_proceso','listo','facturado','cancelado') NOT NULL DEFAULT 'recibido'");
        }
    }
};
