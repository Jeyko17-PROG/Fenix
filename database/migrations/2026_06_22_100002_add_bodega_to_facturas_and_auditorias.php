<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->foreignId('bodega_id')->nullable()->after('owner_id')->constrained('bodegas')->nullOnDelete();
        });

        Schema::table('auditorias', function (Blueprint $table) {
            $table->foreignId('bodega_id')->nullable()->after('usuario_id')->constrained('bodegas')->nullOnDelete();
        });

        DB::table('facturas')->orderBy('id')->chunk(100, function ($facturas) {
            foreach ($facturas as $factura) {
                $bodegaId = DB::table('bodegas')
                    ->where('owner_id', $factura->owner_id)
                    ->orderByDesc('es_principal')
                    ->orderBy('id')
                    ->value('id');

                if ($bodegaId) {
                    DB::table('facturas')->where('id', $factura->id)->update(['bodega_id' => $bodegaId]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('auditorias', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bodega_id');
        });
        Schema::table('facturas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bodega_id');
        });
    }
};
