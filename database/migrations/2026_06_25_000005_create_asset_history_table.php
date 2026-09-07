<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAssetHistoryTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('asset_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_vehicle_id')->constrained('assets_vehicles')->cascadeOnDelete();
            $table->foreignId('service_order_id')->constrained('service_orders')->cascadeOnDelete();
            $table->text('descripcion_trabajo');
            $table->decimal('costo_total', 12, 2);
            $table->string('estado_entrada')->nullable()->comment('Ej: Con falla en motor');
            $table->string('estado_salida')->nullable()->comment('Ej: Reparado y probado');
            $table->integer('km_entrada')->nullable();
            $table->integer('km_salida')->nullable();
            $table->timestamp('fecha_entrada');
            $table->timestamp('fecha_salida')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('asset_vehicle_id');
            $table->index('service_order_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('asset_history');
    }
}
