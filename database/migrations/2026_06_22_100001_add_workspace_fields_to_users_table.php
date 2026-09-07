<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('workspace_owner_id')->nullable()->after('es_super_admin')->constrained('users')->nullOnDelete();
            $table->foreignId('bodega_id')->nullable()->after('workspace_owner_id')->constrained('bodegas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bodega_id');
            $table->dropConstrainedForeignId('workspace_owner_id');
        });
    }
};
