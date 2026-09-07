<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ícono/emoji propio por servicio (ej. 💅 para "Uñas"), para que el cliente
 * y el negocio lo reconozcan de un vistazo en la agenda, el portal y el QR.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('servicios', 'icono')) {
            Schema::table('servicios', function (Blueprint $table) {
                $table->string('icono', 20)->nullable()->after('imagen'); // 20: suficiente para emoji compuestos (piel/género/ZWJ)
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('servicios', 'icono')) {
            Schema::table('servicios', function (Blueprint $table) {
                $table->dropColumn('icono');
            });
        }
    }
};
