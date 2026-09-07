<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            // Vínculo opcional con una cuenta de usuario del portal (cuando el cliente se registra).
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nombre_completo');
            $table->string('tipo_documento', 10)->nullable(); // NIT, CC, CE
            $table->string('numero_documento')->nullable();
            $table->string('email')->nullable();
            $table->string('telefono')->nullable();
            $table->string('direccion')->nullable();
            $table->enum('estado', ['ACTIVO', 'POTENCIAL', 'INACTIVO'])->default('ACTIVO');
            $table->text('seguimiento_comercial')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('nombre_completo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
