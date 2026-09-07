<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Detalle de servicios de una cita (permite varios servicios por atención,
 * ej. Uñas + Pestañas). `servicio_id` nulo = línea personalizada capturada
 * en el momento (nombre y precio manual, sin catálogo). `citas.servicio_id`
 * se conserva para retrocompatibilidad (queda con el primer servicio elegido).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cita_servicio')) {
            Schema::create('cita_servicio', function (Blueprint $table) {
                $table->id();
                $table->foreignId('cita_id')->constrained('citas')->cascadeOnDelete();
                $table->foreignId('servicio_id')->nullable()->constrained('servicios')->nullOnDelete();
                $table->string('nombre_personalizado')->nullable();
                $table->decimal('precio_unitario', 14, 2)->default(0);
                $table->unsignedInteger('duracion_min')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cita_servicio');
    }
};
