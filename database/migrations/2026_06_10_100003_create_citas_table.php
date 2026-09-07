<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('citas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->foreignId('servicio_id')->nullable()->constrained('servicios')->nullOnDelete();
            $table->foreignId('empleado_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('inicio');
            $table->dateTime('fin');
            $table->enum('estado', ['PENDIENTE', 'CONFIRMADA', 'CANCELADA', 'REPROGRAMADA', 'COMPLETADA', 'NO_ASISTIO'])->default('PENDIENTE');
            $table->text('observaciones')->nullable();
            $table->string('origen')->default('ADMIN'); // ADMIN o PORTAL (reserva del cliente)
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['inicio', 'fin']);
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('citas');
    }
};
