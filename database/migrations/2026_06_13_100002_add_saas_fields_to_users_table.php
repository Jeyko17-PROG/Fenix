<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('tipo_documento')->nullable()->after('name');   // CC, CE, NIT, PAS
            $table->string('numero_documento')->nullable()->after('tipo_documento');
            $table->foreignId('plan_id')->nullable()->after('rol_id')->constrained('plans')->nullOnDelete();
            $table->unsignedInteger('limite_clientes')->nullable()->after('plan_id'); // override manual
            $table->string('estado')->default('ACTIVO')->after('activo'); // ACTIVO / SUSPENDIDO / DESACTIVADO
        });

        // Sincroniza el estado con el booleano 'activo' existente.
        DB::table('users')->where('activo', false)->update(['estado' => 'DESACTIVADO']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_id');
            $table->dropColumn(['tipo_documento', 'numero_documento', 'limite_clientes', 'estado']);
        });
    }
};
