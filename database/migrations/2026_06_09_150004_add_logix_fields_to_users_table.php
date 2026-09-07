<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('rol_id')->nullable()->after('id')->constrained('roles')->nullOnDelete();
            $table->string('foto_perfil_url')->nullable()->after('email');
            $table->string('telefono')->nullable()->after('foto_perfil_url');
            $table->boolean('activo')->default(true)->after('telefono');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rol_id');
            $table->dropColumn(['foto_perfil_url', 'telefono', 'activo', 'deleted_at']);
        });
    }
};
