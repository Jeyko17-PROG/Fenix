<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega empresa_id (nullable + index) a todas las tablas de negocio y de cobro.
 * Nullable a propósito: el backfill llena los datos y el NOT NULL queda para una
 * fase posterior, cuando se verifique que no quedan filas huérfanas.
 */
return new class extends Migration
{
    /** Tablas de negocio que hoy se aíslan por owner_id (23). */
    public const TABLAS_NEGOCIO = [
        'clientes', 'citas', 'facturas', 'notas', 'productos', 'movimientos_inventario',
        'proveedores', 'categorias', 'bodegas', 'servicios', 'ordenes_compra',
        'stock_por_bodega', 'documentos', 'horarios_laborales', 'bloqueos_agenda',
        'ajustes_agenda', 'adjuntos', 'operables_employees', 'assets_vehicles',
        'service_orders', 'commission_liquidations', 'caja_sesiones', 'gastos',
    ];

    /** Tablas de cobro/trazabilidad que estaban atadas a user_id. */
    public const TABLAS_COBRO = [
        'user_credits', 'credit_transactions', 'payment_transactions', 'auditorias',
    ];

    public function up(): void
    {
        foreach (array_merge(self::TABLAS_NEGOCIO, self::TABLAS_COBRO) as $tabla) {
            if (! Schema::hasTable($tabla) || Schema::hasColumn($tabla, 'empresa_id')) {
                continue;
            }
            Schema::table($tabla, function (Blueprint $table) {
                $table->foreignId('empresa_id')->nullable()
                    ->constrained('empresas')->nullOnDelete();
                $table->index('empresa_id');
            });
        }
    }

    public function down(): void
    {
        foreach (array_merge(self::TABLAS_NEGOCIO, self::TABLAS_COBRO) as $tabla) {
            if (Schema::hasTable($tabla) && Schema::hasColumn($tabla, 'empresa_id')) {
                Schema::table($tabla, fn (Blueprint $table) => $table->dropConstrainedForeignId('empresa_id'));
            }
        }
    }
};
