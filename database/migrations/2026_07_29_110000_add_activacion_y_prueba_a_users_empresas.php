<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Activación por código de 6 dígitos (visible solo al super-admin) + prueba
 * gratuita de 15 días de calendario, controlada con el mismo motor de
 * membresías que ya existe (modo_cobro='prueba' + membresia_vence_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'codigo_activacion')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('codigo_activacion', 6)->nullable()->after('estado');
                $table->unsignedTinyInteger('codigo_activacion_intentos')->default(0)->after('codigo_activacion');
                $table->unsignedInteger('veces_login')->default(0)->after('ultimo_acceso');
            });
        }

        if (! Schema::hasColumn('empresas', 'prueba_alerta_enviada')) {
            Schema::table('empresas', function (Blueprint $table) {
                $table->boolean('prueba_alerta_enviada')->default(false)->after('membresia_vence_at');
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['codigo_activacion', 'codigo_activacion_intentos', 'veces_login']);
        });
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn('prueba_alerta_enviada');
        });
    }
};
