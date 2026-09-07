<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué servicios ofrece cada sucursal (bodega). Un servicio SIN filas aquí se
 * considera disponible en TODAS las sucursales de la empresa (retrocompatible
 * con los servicios creados antes de multisucursal); en cuanto se le asigna
 * al menos una sucursal, queda limitado a esas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bodega_servicio')) {
            Schema::create('bodega_servicio', function (Blueprint $table) {
                $table->foreignId('bodega_id')->constrained('bodegas')->cascadeOnDelete();
                $table->foreignId('servicio_id')->constrained('servicios')->cascadeOnDelete();
                $table->primary(['bodega_id', 'servicio_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bodega_servicio');
    }
};
