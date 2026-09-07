<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alternativa al logo real (logo_url): el dueño elige un emoji como "marca"
 * del negocio, para cuando no tiene un logo con el que representarse. Se usa
 * en el portal público y el QR cuando no hay logo_url cargado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('empresas', 'logo_emoji')) {
            Schema::table('empresas', function (Blueprint $table) {
                $table->string('logo_emoji', 20)->nullable()->after('logo_url');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('empresas', 'logo_emoji')) {
            Schema::table('empresas', function (Blueprint $table) {
                $table->dropColumn('logo_emoji');
            });
        }
    }
};
