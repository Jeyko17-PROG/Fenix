<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tope de facturas EMITIDAS (no cuenta cotizaciones/BORRADOR) por plan,
        // igual patrón que limite_clientes/limite_citas.
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('limite_facturas')->default(1000)->after('limite_citas');
        });

        // Override manual del super-admin (null = usa el del plan), igual que limite_clientes/limite_citas.
        Schema::table('empresas', function (Blueprint $table) {
            $table->unsignedInteger('limite_facturas')->nullable()->after('limite_citas');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('limite_facturas')->nullable()->after('limite_citas');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('limite_facturas');
        });
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn('limite_facturas');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('limite_facturas');
        });
    }
};
