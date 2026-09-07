<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAssetsVehiclesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('assets_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->cascadeOnDelete();
            $table->string('tipo_activo')->comment('moto, auto, celular, computadora, etc');
            $table->string('placa_identificador')->nullable()->comment('Placa, IMEI, Serie, etc');
            $table->string('marca');
            $table->string('modelo');
            $table->year('anio')->nullable();
            $table->string('color')->nullable();
            $table->text('descripcion')->nullable();
            $table->text('notas_tecnicas')->nullable();
            $table->boolean('activo')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->index('owner_id');
            $table->index('cliente_id');
            $table->index('placa_identificador');
            $table->unique(['owner_id', 'placa_identificador']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('assets_vehicles');
    }
}
