<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // Funcionalidades (feature flags) ACTIVADAS por el plan. El super-admin
            // las edita por plan; null = usar el mapeo por defecto del código.
            $table->json('funcionalidades')->nullable()->after('incluye');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('funcionalidades');
        });
    }
};
