<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateServiceOrderDetailsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('service_order_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_order_id')->constrained('service_orders')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->foreignId('operables_employee_id')->nullable()->constrained('operables_employees')->nullOnDelete();
            $table->integer('cantidad')->default(1);
            $table->decimal('precio_unitario', 12, 2);
            $table->decimal('subtotal', 12, 2);
            $table->boolean('tiene_comision')->default(false);
            $table->enum('tipo_comision', ['percentage', 'fixed'])->nullable();
            $table->decimal('comision_value', 10, 2)->nullable();
            $table->decimal('comision_aplicada', 12, 2)->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();

            $table->index('service_order_id');
            $table->index('producto_id');
            $table->index('operables_employee_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('service_order_details');
    }
}
