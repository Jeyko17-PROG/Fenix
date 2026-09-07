<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('factura_detalle', function (Blueprint $table) {
            // IVA configurable por línea (0 / 5 / 19 / personalizado).
            $table->decimal('impuesto_porcentaje', 5, 2)->default(0)->after('precio_unitario');
            // Monto de impuesto calculado de esa línea (cant * precio * pct / 100).
            $table->decimal('impuesto', 14, 2)->default(0)->after('subtotal');
        });
    }

    public function down(): void
    {
        Schema::table('factura_detalle', function (Blueprint $table) {
            $table->dropColumn(['impuesto_porcentaje', 'impuesto']);
        });
    }
};
