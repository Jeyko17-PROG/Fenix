<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Modalidad de cobro de la plataforma:
            // 'membresia' = plan mensual (bloqueo al vencer) | 'prepago' = paga $500 COP por factura (créditos)
            $table->string('modo_cobro')->default('membresia')->after('plan_id');
            // Fecha en la que vence la membresía mensual. NULL = sin control de vencimiento (cuentas antiguas).
            $table->timestamp('membresia_vence_at')->nullable()->after('modo_cobro');
        });

        Schema::table('operables_employees', function (Blueprint $table) {
            // Cuenta de acceso (login) del empleado, para el rol Mecanico.
            $table->foreignId('user_id')->nullable()->after('owner_id')->constrained('users')->nullOnDelete();
        });

        Schema::table('service_orders', function (Blueprint $table) {
            // Mecánico/técnico responsable de la orden (además del asignado por detalle).
            $table->foreignId('operables_employee_id')->nullable()->after('asset_vehicle_id')->constrained('operables_employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('operables_employee_id');
        });
        Schema::table('operables_employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['modo_cobro', 'membresia_vence_at']);
        });
    }
};
