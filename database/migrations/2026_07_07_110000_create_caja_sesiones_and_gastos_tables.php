<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Turnos de caja: apertura y cierre con arqueo (multi-caja por bodega/cajero).
        Schema::create('caja_sesiones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // cajero del turno
            $table->foreignId('bodega_id')->nullable()->constrained('bodegas')->nullOnDelete();
            $table->string('estado')->default('ABIERTA'); // ABIERTA | CERRADA
            $table->decimal('monto_apertura', 14, 2)->default(0);   // base con la que abre el turno
            $table->decimal('monto_esperado', 14, 2)->nullable();   // apertura + ventas - gastos (calculado al cerrar)
            $table->decimal('monto_cierre', 14, 2)->nullable();     // efectivo contado por el cajero
            $table->decimal('descuadre', 14, 2)->nullable();        // cierre - esperado (negativo = faltó dinero)
            $table->text('notas_apertura')->nullable();
            $table->text('notas_cierre')->nullable();
            $table->timestamp('abierta_at');
            $table->timestamp('cerrada_at')->nullable();
            $table->timestamps();

            $table->index(['owner_id', 'estado']);
        });

        // Gastos diarios registrados desde la caja (arriendo, servicios, papelería...).
        Schema::create('gastos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // quién lo registró
            $table->foreignId('caja_sesion_id')->nullable()->constrained('caja_sesiones')->nullOnDelete();
            $table->foreignId('bodega_id')->nullable()->constrained('bodegas')->nullOnDelete();
            $table->string('categoria'); // arriendo, servicios, papeleria, nomina, otros
            $table->string('descripcion');
            $table->decimal('monto', 14, 2);
            $table->date('fecha');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gastos');
        Schema::dropIfExists('caja_sesiones');
    }
};
