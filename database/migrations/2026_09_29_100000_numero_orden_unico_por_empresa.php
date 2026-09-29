<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * numero_orden se generaba como "SO-YYYYMMDD-00001" contando solo las
 * órdenes del owner (empresa), pero la columna era UNIQUE a nivel global:
 * dos empresas distintas creando su primera orden del día chocaban ambas
 * en "00001" y la segunda fallaba con un error 500 de base de datos. La
 * unicidad real que se necesita es por empresa (owner_id), no global.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropUnique('service_orders_numero_orden_unique');
            $table->unique(['owner_id', 'numero_orden']);
        });
    }

    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropUnique(['owner_id', 'numero_orden']);
            $table->unique('numero_orden');
        });
    }
};
