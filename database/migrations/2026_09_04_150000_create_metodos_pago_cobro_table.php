<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metodos_pago_cobro', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->cascadeOnDelete();
            // Tipo libre (Nequi, Daviplata, Bancolombia, Link de pago, Otro...):
            // no un enum cerrado, porque cada negocio usa billeteras distintas.
            $table->string('tipo');
            // Etiqueta visible para diferenciar dos cuentas del mismo tipo
            // (ej. "Nequi personal" vs "Nequi del negocio").
            $table->string('nombre')->nullable();
            $table->string('numero_cuenta')->nullable();
            $table->string('enlace')->nullable();
            $table->string('qr_url')->nullable();
            $table->boolean('activo')->default(true);
            $table->unsignedInteger('orden')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metodos_pago_cobro');
    }
};
