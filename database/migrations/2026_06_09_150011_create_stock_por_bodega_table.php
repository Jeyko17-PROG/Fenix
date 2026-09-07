<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_por_bodega', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->foreignId('bodega_id')->constrained('bodegas')->cascadeOnDelete();
            $table->decimal('cantidad', 14, 4)->default(0);
            $table->decimal('stock_minimo', 14, 4)->default(0);
            $table->decimal('costo_promedio', 14, 4)->default(0);
            $table->timestamps();

            $table->unique(['producto_id', 'bodega_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_por_bodega');
    }
};
