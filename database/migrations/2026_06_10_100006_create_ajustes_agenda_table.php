<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Configuración global de la agenda (una sola fila).
        Schema::create('ajustes_agenda', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('duracion_cita_min')->default(30);
            $table->unsignedInteger('buffer_min')->default(0); // tiempo entre citas
            $table->timestamps();
        });

        // Fila por defecto.
        \Illuminate\Support\Facades\DB::table('ajustes_agenda')->insert([
            'duracion_cita_min' => 30,
            'buffer_min' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ajustes_agenda');
    }
};
