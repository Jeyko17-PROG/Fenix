<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Políticas visibles en el portal público (ej. requisitos de edad,
     * abono/cancelación) y enlaces a redes sociales para el header del QR
     * de reservas. Aplica a CUALQUIER tipo de negocio, no solo tatuajes.
     */
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->text('politicas')->nullable()->after('direccion');
            $table->string('instagram_url')->nullable()->after('politicas');
            $table->string('tiktok_url')->nullable()->after('instagram_url');
            $table->string('facebook_url')->nullable()->after('tiktok_url');
            $table->string('whatsapp_url')->nullable()->after('facebook_url');
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn(['politicas', 'instagram_url', 'tiktok_url', 'facebook_url', 'whatsapp_url']);
        });
    }
};
