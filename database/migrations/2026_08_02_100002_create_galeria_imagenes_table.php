<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Galería de varias imágenes por registro (productos, servicios y, más
     * adelante, citas). Polimórfica para no repetir la misma tabla por cada
     * modelo: hoy la necesitan Producto y Servicio (ej. una barbería subiendo
     * varios cortes de referencia por servicio), pero cualquier modelo nuevo
     * puede sumarse solo agregando la relación morphMany.
     */
    public function up(): void
    {
        Schema::create('galeria_imagenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->nullOnDelete();
            $table->morphs('imageable');
            $table->string('url');
            $table->string('public_id')->nullable();
            $table->unsignedInteger('orden')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('galeria_imagenes');
    }
};
