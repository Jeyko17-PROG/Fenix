<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Especialista (barbero/estilista/esteticien) asignado a la cita, elegido por
 * el propio cliente en el portal público. Es independiente de `empleado_id`
 * (que apunta a usuarios con acceso al sistema): el especialista viene del
 * roster operativo (operables_employees) usado también para comisiones en
 * las órdenes de servicio, y no necesita tener una cuenta de acceso.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('citas', 'operables_employee_id')) {
            Schema::table('citas', function (Blueprint $table) {
                $table->foreignId('operables_employee_id')->nullable()->after('empleado_id')
                    ->constrained('operables_employees')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('citas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('operables_employee_id');
        });
    }
};
