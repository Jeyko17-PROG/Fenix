<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo restaurante (mesas + comandas + cocina) y checklist de entrada
 * para talleres. Todo aditivo e idempotente (auto-migrate de Render).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Plano de mesas del restaurante.
        if (! Schema::hasTable('mesas')) {
            Schema::create('mesas', function (Blueprint $table) {
                $table->id();
                $table->foreignId('empresa_id')->nullable()->constrained('empresas')->nullOnDelete();
                $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('nombre');                    // "Mesa 1", "Barra", "Terraza 2"
                $table->string('estado')->default('LIBRE');  // LIBRE | OCUPADA | RESERVADA
                $table->unsignedInteger('capacidad')->default(4);
                $table->unsignedInteger('orden')->default(0);
                $table->timestamps();
                $table->index(['empresa_id', 'estado']);
            });
        }

        // Comanda: el pedido abierto de una mesa (el mesero agrega ítems y la
        // cocina los va preparando; al cerrar se convierte en factura).
        if (! Schema::hasTable('comandas')) {
            Schema::create('comandas', function (Blueprint $table) {
                $table->id();
                $table->foreignId('empresa_id')->nullable()->constrained('empresas')->nullOnDelete();
                $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('mesa_id')->constrained('mesas')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // mesero
                $table->string('estado')->default('ABIERTA'); // ABIERTA | COBRADA | CANCELADA
                $table->string('notas')->nullable();
                $table->foreignId('factura_id')->nullable()->constrained('facturas')->nullOnDelete();
                $table->timestamps();
                $table->index(['empresa_id', 'estado']);
            });
        }

        if (! Schema::hasTable('comanda_items')) {
            Schema::create('comanda_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('comanda_id')->constrained('comandas')->cascadeOnDelete();
                $table->foreignId('producto_id')->nullable()->constrained('productos')->nullOnDelete();
                $table->string('descripcion');
                $table->decimal('cantidad', 10, 2)->default(1);
                $table->decimal('precio_unitario', 14, 2)->default(0);
                $table->decimal('subtotal', 14, 2)->default(0);
                $table->string('estado_cocina')->default('PENDIENTE'); // PENDIENTE | PREPARANDO | LISTO | ENTREGADO
                $table->string('notas')->nullable(); // "sin cebolla", "término medio"
                $table->timestamps();
                $table->index(['comanda_id', 'estado_cocina']);
            });
        }

        // La factura de un restaurante queda atada a su mesa.
        if (! Schema::hasColumn('facturas', 'mesa_id')) {
            Schema::table('facturas', function (Blueprint $table) {
                $table->foreignId('mesa_id')->nullable()->constrained('mesas')->nullOnDelete();
            });
        }

        // Checklist de entrada del vehículo/equipo en talleres (JSON:
        // [{item: "Espejos", ok: true}, ...] + observaciones libres).
        if (! Schema::hasColumn('service_orders', 'checklist_entrada')) {
            Schema::table('service_orders', function (Blueprint $table) {
                $table->json('checklist_entrada')->nullable()->after('accesorios');
            });
        }
    }

    public function down(): void
    {
        Schema::table('service_orders', fn (Blueprint $t) => $t->dropColumn('checklist_entrada'));
        Schema::table('facturas', fn (Blueprint $t) => $t->dropConstrainedForeignId('mesa_id'));
        Schema::dropIfExists('comanda_items');
        Schema::dropIfExists('comandas');
        Schema::dropIfExists('mesas');
    }
};
