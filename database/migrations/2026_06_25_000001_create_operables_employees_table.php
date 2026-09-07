<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOperablesEmployeesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('operables_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('nombre');
            $table->string('apellido');
            $table->string('email')->nullable();
            $table->string('telefono')->nullable();
            $table->string('ci_cedula')->unique();
            $table->enum('tipo_operario', ['mecanico', 'electricista', 'esteticien', 'tecnico', 'asesor', 'otro'])
                ->default('mecanico');
            $table->decimal('comision_default', 8, 2)->nullable()->comment('Comisión por defecto si no se especifica por servicio');
            $table->enum('tipo_comision_default', ['percentage', 'fixed'])->nullable();
            $table->boolean('activo')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->index('owner_id');
            $table->index('activo');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('operables_employees');
    }
}
