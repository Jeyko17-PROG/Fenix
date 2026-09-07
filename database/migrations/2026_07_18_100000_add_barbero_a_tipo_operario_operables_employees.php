<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Amplía el ENUM de operables_employees.tipo_operario para incluir 'barbero'.
 * En PostgreSQL la columna ya es un varchar/string sin restricción, así que
 * no requiere cambios ahí (mismo criterio que 2026_07_16_100002).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE operables_employees MODIFY tipo_operario ENUM('mecanico','electricista','esteticien','tecnico','asesor','lavador','barbero','otro') NOT NULL DEFAULT 'mecanico'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE operables_employees MODIFY tipo_operario ENUM('mecanico','electricista','esteticien','tecnico','asesor','lavador','otro') NOT NULL DEFAULT 'mecanico'");
        }
    }
};
