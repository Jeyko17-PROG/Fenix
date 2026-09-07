<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos_inventario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->enum('tipo', ['ENTRADA', 'SALIDA', 'TRASLADO', 'AJUSTE']);
            $table->string('motivo')->nullable(); // COMPRA, VENTA, PERDIDA, DEVOLUCION, AJUSTE_FISICO, TRASLADO
            $table->foreignId('bodega_origen_id')->nullable()->constrained('bodegas')->nullOnDelete();
            $table->foreignId('bodega_destino_id')->nullable()->constrained('bodegas')->nullOnDelete();
            $table->decimal('cantidad', 14, 4);
            $table->decimal('costo_unitario', 14, 4)->default(0);
            $table->decimal('costo_promedio_resultante', 14, 4)->nullable();
            $table->decimal('stock_resultante', 14, 4)->nullable();
            $table->string('referencia_tipo')->nullable();
            $table->unsignedBigInteger('referencia_id')->nullable();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['producto_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_inventario');
    }
};
