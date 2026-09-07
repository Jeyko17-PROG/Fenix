<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firmas_electronicas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('documento_id')->constrained('documentos')->cascadeOnDelete();
            $table->enum('estado', ['PENDIENTE', 'FIRMADO', 'RECHAZADO'])->default('PENDIENTE');
            $table->foreignId('firmante_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('hash_documento')->nullable();
            $table->string('proveedor_firma')->nullable();
            $table->json('payload_respuesta')->nullable();
            $table->timestamp('fecha_firma')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firmas_electronicas');
    }
};
