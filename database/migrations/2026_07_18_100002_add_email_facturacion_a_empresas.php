<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remitente propio por empresa al enviar facturas: si lo configura, las
 * facturas salen con su correo en From/Reply-To en vez del genérico de Logix.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('empresas', 'email_facturacion')) {
            Schema::table('empresas', function (Blueprint $table) {
                $table->string('email_facturacion')->nullable()->after('email');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('empresas', 'email_facturacion')) {
            Schema::table('empresas', function (Blueprint $table) {
                $table->dropColumn('email_facturacion');
            });
        }
    }
};
