<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Último acceso del usuario (se actualiza en cada login).
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('ultimo_acceso')->nullable()->after('estado');
        });

        // Overrides de funcionalidades por usuario (Control de Funcionalidades).
        Schema::create('user_funcionalidades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('clave');                 // clientes, facturacion, inventario, ...
            $table->string('estado');                // ACTIVADA, RESTRINGIDA, DESACTIVADA
            $table->timestamps();
            $table->unique(['user_id', 'clave']);
        });

        // Bitácora de cambios del Super Administrador.
        Schema::create('auditorias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('accion');                // ESTADO, PLAN, LIMITE, FUNCIONALIDAD
            $table->string('funcionalidad')->nullable();
            $table->string('estado_anterior')->nullable();
            $table->string('estado_nuevo')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auditorias');
        Schema::dropIfExists('user_funcionalidades');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ultimo_acceso');
        });
    }
};
