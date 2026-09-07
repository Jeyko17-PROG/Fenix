<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCommissionLiquidationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('commission_liquidations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('operables_employee_id')->constrained('operables_employees')->cascadeOnDelete();
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->decimal('monto_total', 12, 2);
            $table->enum('estado', ['pendiente', 'pagada', 'cancelada'])->default('pendiente');
            $table->timestamp('fecha_pago')->nullable();
            $table->string('referencia_pago')->nullable();
            $table->text('notas')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('owner_id');
            $table->index('operables_employee_id');
            $table->index('estado');
            $table->unique(['operables_employee_id', 'fecha_inicio', 'fecha_fin'], 'comm_liq_emp_dates_unique');
        }); // <-- ¡Esta llave y paréntesis hacían falta para cerrar el Schema::create!
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('commission_liquidations');
    }
}