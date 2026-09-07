<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateServiceOrdersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('service_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->foreignId('asset_vehicle_id')->nullable()->constrained('assets_vehicles')->cascadeOnDelete();
            $table->string('numero_orden')->unique();
            $table->enum('estado', ['recibido', 'en_proceso', 'listo', 'facturado', 'cancelado'])
                ->default('recibido');
            $table->text('descripcion_trabajo')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('total_comisiones', 12, 2)->default(0);
            $table->foreignId('factura_id')->nullable()->constrained('facturas')->nullOnDelete();
            $table->timestamp('fecha_recepcion')->useCurrent();
            $table->timestamp('fecha_entrega_estimada')->nullable();
            $table->timestamp('fecha_entrega_real')->nullable();
            $table->boolean('requiere_pago_anticipo')->default(false);
            $table->decimal('monto_anticipo', 12, 2)->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('owner_id');
            $table->index('cliente_id');
            $table->index('estado');
            $table->index('numero_orden');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('service_orders');
    }
}
