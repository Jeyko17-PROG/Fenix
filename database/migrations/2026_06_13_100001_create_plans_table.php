<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();          // Normal / Medio / Premium
            $table->unsignedInteger('precio_mensual');    // COP
            $table->unsignedInteger('limite_clientes');   // tope de clientes
            $table->json('incluye')->nullable();          // lista de características
            $table->boolean('activo')->default(true);
            $table->unsignedInteger('orden')->default(0); // para ordenar al mostrar
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
