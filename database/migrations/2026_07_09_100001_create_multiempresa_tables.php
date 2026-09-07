<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Base del modelo multiempresa (SaaS multi-tenant):
 * tipos_negocio, modulos, empresas y empresa_modulos + vínculo users→empresa.
 * Todo aditivo e idempotente: seguro para el auto-migrate de Render.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tipos_negocio')) {
            Schema::create('tipos_negocio', function (Blueprint $table) {
                $table->id();
                $table->string('clave')->unique();      // taller_motos, lavadero, tienda...
                $table->string('nombre');
                $table->string('descripcion')->nullable();
                $table->json('modulos_default')->nullable(); // claves del catálogo de módulos
                $table->boolean('activo')->default(true);
                $table->unsignedInteger('orden')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('modulos')) {
            Schema::create('modulos', function (Blueprint $table) {
                $table->id();
                $table->string('clave')->unique();      // misma clave que Funcionalidades::CATALOGO
                $table->string('nombre');
                $table->string('descripcion')->nullable();
                $table->boolean('activo')->default(true);
                $table->unsignedInteger('orden')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('empresas')) {
            Schema::create('empresas', function (Blueprint $table) {
                $table->id();
                $table->string('nombre');
                $table->string('tipo_documento')->nullable();   // NIT, CC...
                $table->string('numero_documento')->nullable();
                $table->string('telefono')->nullable();
                $table->string('email')->nullable();
                $table->string('direccion')->nullable();
                $table->string('logo_url')->nullable();
                $table->foreignId('tipo_negocio_id')->nullable()->constrained('tipos_negocio')->nullOnDelete();
                // Dueño principal. UNIQUE: es la clave de idempotencia del backfill.
                $table->foreignId('owner_user_id')->unique()->constrained('users')->cascadeOnDelete();
                // Cobro SaaS (antes vivía en users):
                $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
                $table->string('modo_cobro')->default('membresia'); // membresia | prepago
                $table->timestamp('membresia_vence_at')->nullable();
                $table->string('estado')->default('ACTIVO');        // ACTIVO | SUSPENDIDO | DESACTIVADO
                $table->boolean('activo')->default(true);
                $table->unsignedInteger('limite_clientes')->nullable(); // override manual del super-admin
                $table->string('reservas_slug')->unique()->nullable();  // portal público de reservas
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('empresa_modulos')) {
            Schema::create('empresa_modulos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
                $table->foreignId('modulo_id')->constrained('modulos')->cascadeOnDelete();
                $table->string('estado')->default('ACTIVADA'); // ACTIVADA | RESTRINGIDA | DESACTIVADA
                $table->timestamps();
                $table->unique(['empresa_id', 'modulo_id']);
            });
        }

        if (! Schema::hasColumn('users', 'empresa_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('empresa_id')->nullable()->after('workspace_owner_id')
                    ->constrained('empresas')->nullOnDelete();
                // Administrador de SU empresa (segundo nivel; el primero es es_super_admin).
                $table->boolean('es_admin_empresa')->default(false)->after('es_super_admin');
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('empresa_id');
            $table->dropColumn('es_admin_empresa');
        });
        Schema::dropIfExists('empresa_modulos');
        Schema::dropIfExists('empresas');
        Schema::dropIfExists('modulos');
        Schema::dropIfExists('tipos_negocio');
    }
};
