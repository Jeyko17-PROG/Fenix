<?php

namespace Tests\Feature;

use App\Business\Infrastructure\Persistence\Eloquent\Bodega;
use App\IAM\Infrastructure\Persistence\Eloquent\Role;
use App\IAM\Infrastructure\Persistence\Eloquent\User;
use App\Operations\Infrastructure\Persistence\Eloquent\Cliente;
use App\Operations\Infrastructure\Persistence\Eloquent\Producto;
use App\Operations\Infrastructure\Persistence\Eloquent\ServiceOrder;
use App\Operations\Infrastructure\Persistence\Eloquent\StockBodega;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El módulo de Taller no tenía forma de eliminar una orden completa (solo
 * se podían quitar ítems sueltos) - se agregó DELETE /ordenes-servicio/{id}.
 */
class OrdenServicioEliminarTest extends TestCase
{
    use RefreshDatabase;

    private function crearUsuario(): User
    {
        $role = Role::create(['nombre' => 'Administrador', 'descripcion' => 'Administrador']);
        return User::factory()->create(['rol_id' => $role->id, 'estado' => 'ACTIVO', 'activo' => true]);
    }

    public function test_elimina_una_orden_y_devuelve_el_repuesto_al_inventario(): void
    {
        $user = $this->crearUsuario();
        $bodega = Bodega::create(['owner_id' => $user->id, 'nombre' => 'Principal', 'direccion' => 'Calle 1', 'responsable_id' => $user->id, 'activo' => true, 'es_principal' => true]);
        $cliente = Cliente::create(['owner_id' => $user->id, 'nombre_completo' => 'Cliente Orden']);
        $producto = Producto::create(['owner_id' => $user->id, 'nombre' => 'Filtro de aceite', 'sku' => 'F-1', 'precio_venta' => 20000, 'is_service' => false, 'activo' => true]);
        StockBodega::create(['owner_id' => $user->id, 'producto_id' => $producto->id, 'bodega_id' => $bodega->id, 'cantidad' => 10, 'costo_promedio' => 12000]);

        $this->actingAs($user, 'sanctum');

        $orden = $this->postJson('/api/ordenes-servicio', ['cliente_id' => $cliente->id])
            ->assertStatus(201)->json();

        $this->postJson("/api/ordenes-servicio/{$orden['id']}/detalles", [
            'producto_id' => $producto->id,
            'cantidad' => 3,
            'precio_unitario' => 20000,
        ])->assertStatus(201);

        $this->assertSame(7.0, (float) StockBodega::where('producto_id', $producto->id)->where('bodega_id', $bodega->id)->value('cantidad'));

        $this->deleteJson("/api/ordenes-servicio/{$orden['id']}")->assertStatus(200);

        // ServiceOrder usa soft-delete (igual que el resto del sistema): la fila
        // sigue en la tabla para auditoría, pero ya no aparece en consultas normales.
        $this->assertNull(ServiceOrder::find($orden['id']));
        $this->assertNotNull(ServiceOrder::withTrashed()->find($orden['id'])->deleted_at);
        $this->assertDatabaseMissing('service_order_details', ['service_order_id' => $orden['id']]);
        // El repuesto vuelve al inventario, como cuando se quita un solo ítem.
        $this->assertSame(10.0, (float) StockBodega::where('producto_id', $producto->id)->where('bodega_id', $bodega->id)->value('cantidad'));
    }

    public function test_no_deja_eliminar_una_orden_ya_facturada(): void
    {
        $user = $this->crearUsuario();
        Bodega::create(['owner_id' => $user->id, 'nombre' => 'Principal', 'direccion' => 'Calle 1', 'responsable_id' => $user->id, 'activo' => true, 'es_principal' => true]);
        $cliente = Cliente::create(['owner_id' => $user->id, 'nombre_completo' => 'Cliente Orden 2']);

        $this->actingAs($user, 'sanctum');

        $orden = $this->postJson('/api/ordenes-servicio', ['cliente_id' => $cliente->id])->assertStatus(201)->json();
        ServiceOrder::find($orden['id'])->update(['estado' => 'facturado']);

        $this->deleteJson("/api/ordenes-servicio/{$orden['id']}")->assertStatus(422);
        $this->assertDatabaseHas('service_orders', ['id' => $orden['id']]);
    }
}
