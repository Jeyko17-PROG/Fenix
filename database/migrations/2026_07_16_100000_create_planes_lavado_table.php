<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planes de lavado (Lavadero): a diferencia de `servicios` (genérico para
 * todos los tipos de negocio), estos planes son propios del lavadero y
 * saben si aplican a moto, a carro, o a ambos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('planes_lavado')) {
            return;
        }

        Schema::create('planes_lavado', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->decimal('precio', 14, 2)->default(0);
            $table->unsignedInteger('duracion_min')->default(30);
            $table->boolean('aplica_moto')->default(true);
            $table->boolean('aplica_carro')->default(true);
            $table->string('icono')->nullable();
            $table->unsignedInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['empresa_id', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planes_lavado');
    }
};
