<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flujos por tipo de negocio:
 *  - facturas.metodo_pago (EFECTIVO/TARJETA/TRANSFERENCIA/NEQUI/DAVIPLATA) y propina
 *    -> cierre de caja desglosado por método de pago.
 *  - service_orders: km_entrada y nivel_gasolina (talleres) y accesorios (servicio técnico).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('facturas', 'metodo_pago')) {
            Schema::table('facturas', function (Blueprint $table) {
                $table->string('metodo_pago')->default('EFECTIVO')->after('estado');
                $table->decimal('propina', 12, 2)->nullable()->after('metodo_pago');
            });
        }

        if (! Schema::hasColumn('service_orders', 'km_entrada')) {
            Schema::table('service_orders', function (Blueprint $table) {
                $table->unsignedInteger('km_entrada')->nullable()->after('descripcion_trabajo');
                $table->unsignedTinyInteger('nivel_gasolina')->nullable()->after('km_entrada'); // 0-100 %
                $table->string('accesorios')->nullable()->after('nivel_gasolina'); // cargador, estuche...
            });
        }
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->dropColumn(['metodo_pago', 'propina']);
        });
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropColumn(['km_entrada', 'nivel_gasolina', 'accesorios']);
        });
    }
};
