<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multisucursal: a qué sucursal (bodega) pertenece la cita. Nullable: los
 * negocios de una sola sede no la usan y el comportamiento no cambia.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('citas', 'bodega_id')) {
            Schema::table('citas', function (Blueprint $table) {
                $table->foreignId('bodega_id')->nullable()->after('empleado_id')
                    ->constrained('bodegas')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('citas', 'bodega_id')) {
            Schema::table('citas', function (Blueprint $table) {
                $table->dropConstrainedForeignId('bodega_id');
            });
        }
    }
};
