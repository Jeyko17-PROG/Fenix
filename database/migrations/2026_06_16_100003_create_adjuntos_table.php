<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adjuntos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            // Relación polimórfica: proveedor, cliente, etc.
            $table->string('adjuntable_tipo');
            $table->unsignedBigInteger('adjuntable_id');
            $table->string('categoria')->nullable(); // Cámara de comercio, RUT, Contrato, etc.
            $table->string('nombre');                 // nombre visible / original
            $table->string('ruta');                   // ruta en el disco
            $table->string('url')->nullable();        // URL pública
            $table->string('tipo_mime')->nullable();
            $table->unsignedBigInteger('tamano_bytes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['adjuntable_tipo', 'adjuntable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adjuntos');
    }
};
