<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multiempresa: el número de factura es único POR EMPRESA (cada negocio lleva
 * su propia secuencia FAC-00001...). El unique global anterior hacía chocar a
 * la primera factura de cada empresa nueva con las de las demás.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            try {
                $table->dropUnique('facturas_numero_unique');
            } catch (\Throwable $e) {
                // el índice puede no existir con ese nombre en instalaciones viejas
            }
        });

        Schema::table('facturas', function (Blueprint $table) {
            $table->unique(['empresa_id', 'numero'], 'facturas_empresa_numero_unique');
        });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->dropUnique('facturas_empresa_numero_unique');
            $table->unique('numero');
        });
    }
};
