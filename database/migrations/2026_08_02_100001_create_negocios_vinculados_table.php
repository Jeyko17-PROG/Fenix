<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vincula dos cuentas (dos filas de `users`, cada una dueña de su propio
     * negocio/empresa) que pertenecen a la misma persona, para que pueda
     * cambiar de una a otra sin cerrar sesión ("Mis negocios"). No toca el
     * aislamiento multi-inquilino existente (owner_id/empresa_id): cada
     * negocio sigue siendo 100% independiente, esto solo guarda el permiso
     * de cambiar de uno a otro.
     */
    public function up(): void
    {
        Schema::create('negocios_vinculados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('vinculado_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'vinculado_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('negocios_vinculados');
    }
};
