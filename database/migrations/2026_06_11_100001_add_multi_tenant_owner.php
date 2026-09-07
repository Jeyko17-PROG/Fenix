<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tablas de datos que pasan a pertenecer a un usuario (owner_id). */
    private array $tablas = [
        'clientes', 'citas', 'facturas', 'notas', 'productos', 'movimientos_inventario',
        'proveedores', 'categorias', 'bodegas', 'servicios', 'ordenes_compra',
        'stock_por_bodega', 'documentos', 'horarios_laborales', 'bloqueos_agenda', 'ajustes_agenda',
    ];

    public function up(): void
    {
        // 1) Marca de super-administrador.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('es_super_admin')->default(false)->after('activo');
        });
        DB::table('users')->where('email', 'luisgarciab193@gmail.com')->update(['es_super_admin' => true]);

        // 2) Propietario del negocio para los datos existentes (el super-admin, o el primer usuario).
        $ownerId = DB::table('users')->where('es_super_admin', true)->value('id')
            ?? DB::table('users')->orderBy('id')->value('id');

        // 3) owner_id en cada tabla de datos + backfill de lo existente.
        foreach ($this->tablas as $tabla) {
            if (! Schema::hasColumn($tabla, 'owner_id')) {
                Schema::table($tabla, function (Blueprint $table) {
                    $table->foreignId('owner_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
                });
            }
            if ($ownerId) {
                DB::table($tabla)->whereNull('owner_id')->update(['owner_id' => $ownerId]);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tablas as $tabla) {
            if (Schema::hasColumn($tabla, 'owner_id')) {
                Schema::table($tabla, function (Blueprint $table) {
                    $table->dropConstrainedForeignId('owner_id');
                });
            }
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('es_super_admin');
        });
    }
};
