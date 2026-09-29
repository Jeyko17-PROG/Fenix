<?php

namespace Tests\Feature;

use App\Business\Infrastructure\Persistence\Eloquent\Bodega;
use App\IAM\Infrastructure\Persistence\Eloquent\Role;
use App\IAM\Infrastructure\Persistence\Eloquent\User;
use App\Operations\Infrastructure\Persistence\Eloquent\OrdenCompra;
use App\Operations\Infrastructure\Persistence\Eloquent\Producto;
use App\Operations\Infrastructure\Persistence\Eloquent\Proveedor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El módulo de Compras no tenía forma de eliminar una orden creada por
 * error (solo existía crear/recibir/pdf) - se agregó DELETE /ordenes-compra/{id},
 * bloqueado una vez RECIBIDA porque ya movió inventario real.
 */
class OrdenCompraEliminarTest extends TestCase
{
    use RefreshDatabase;

    private function crearUsuario(): User
    {
        $role = Role::create(['nombre' => 'Administrador', 'descripcion' => 'Administrador']);
        return User::factory()->create(['rol_id' => $role->id, 'estado' => 'ACTIVO', 'activo' => true]);
    }

    private function crearOrden(User $user): array
    {
        $bodega = Bodega::create(['owner_id' => $user->id, 'nombre' => 'Principal', 'direccion' => 'Calle 1', 'responsable_id' => $user->id, 'activo' => true, 'es_principal' => true]);
        $proveedor = Proveedor::create(['owner_id' => $user->id, 'razon_social' => 'Repuestos SAS', 'tipo_documento' => 'NIT', 'numero_documento' => '900123456']);
        $producto = Producto::create(['owner_id' => $user->id, 'nombre' => 'Filtro de aceite', 'sku' => 'F-1', 'precio_venta' => 20000, 'is_service' => false, 'activo' => true]);

        $orden = $this->postJson('/api/ordenes-compra', [
            'proveedor_id' => $proveedor->id,
            'bodega_id' => $bodega->id,
            'fecha' => now()->toDateString(),
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 5, 'precio_unitario' => 10000]],
        ])->assertStatus(201)->json();

        return [$orden, $producto, $bodega];
    }

    public function test_elimina_una_orden_en_borrador(): void
    {
        $user = $this->crearUsuario();
        $this->actingAs($user, 'sanctum');
        [$orden] = $this->crearOrden($user);

        $this->deleteJson("/api/ordenes-compra/{$orden['id']}")->assertStatus(200);

        $this->assertNull(OrdenCompra::find($orden['id']));
        $this->assertDatabaseMissing('orden_compra_detalle', ['orden_compra_id' => $orden['id']]);
    }

    public function test_no_deja_eliminar_una_orden_ya_recibida(): void
    {
        $user = $this->crearUsuario();
        $this->actingAs($user, 'sanctum');
        [$orden] = $this->crearOrden($user);

        $this->postJson("/api/ordenes-compra/{$orden['id']}/recibir")->assertStatus(200);

        $this->deleteJson("/api/ordenes-compra/{$orden['id']}")->assertStatus(422);
        $this->assertDatabaseHas('ordenes_compra', ['id' => $orden['id']]);
    }
}
