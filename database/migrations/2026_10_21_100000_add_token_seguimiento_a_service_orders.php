<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Token único por orden para el QR/enlace público de seguimiento: el cliente
 * lo escanea y ve el estado de su vehículo sin necesidad de cuenta ni login,
 * igual que el portal de reservas pero para una orden puntual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->string('token_seguimiento', 40)->nullable()->unique()->after('numero_orden');
        });

        // Backfill: las órdenes que ya existían no tienen token; se les asigna
        // uno para que también puedan tener su QR de seguimiento.
        DB::table('service_orders')->whereNull('token_seguimiento')->orderBy('id')
            ->pluck('id')->each(function ($id) {
                DB::table('service_orders')->where('id', $id)->update(['token_seguimiento' => Str::random(32)]);
            });
    }

    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropColumn('token_seguimiento');
        });
    }
};
