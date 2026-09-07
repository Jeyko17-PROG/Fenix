<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tope de citas por plan (obligatorio, como limite_clientes); el seeder define el valor por plan.
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('limite_citas')->default(100)->after('limite_clientes');
        });

        // Override manual del super-admin (null = usa el del plan), igual que limite_clientes.
        Schema::table('empresas', function (Blueprint $table) {
            $table->unsignedInteger('limite_citas')->nullable()->after('limite_clientes');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('limite_citas')->nullable()->after('limite_clientes');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('limite_citas');
        });
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn('limite_citas');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('limite_citas');
        });
    }
};
