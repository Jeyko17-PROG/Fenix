<?php

use App\Support\BackfillEmpresas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Puebla el modelo multiempresa desde el esquema anterior (owner_id).
 * Idempotente: puede correr en cada deploy sin duplicar datos.
 * La misma lógica está disponible como comando: php artisan logix:backfill-empresas
 */
return new class extends Migration
{
    public function up(): void
    {
        $resumen = BackfillEmpresas::run();
        Log::info('Backfill de empresas ejecutado', $resumen);
    }

    public function down(): void
    {
        // Sin reversa: el backfill solo llena columnas nuevas; el esquema
        // se revierte con las migraciones que las crearon.
    }
};
