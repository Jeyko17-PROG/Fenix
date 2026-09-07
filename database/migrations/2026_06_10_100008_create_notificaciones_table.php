<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notificaciones', function (Blueprint $table) {
            $table->id();
            // Destinatario interno (usuario del sistema). Nulo = notificación general/admin.
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('tipo'); // RESERVA, RECORDATORIO, CANCELACION, REPROGRAMACION, FACTURA, ADMIN
            $table->string('titulo');
            $table->text('mensaje')->nullable();
            $table->string('canal')->default('INTERNA'); // INTERNA, EMAIL
            $table->boolean('leida')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'leida']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificaciones');
    }
};
