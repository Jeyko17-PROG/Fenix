<?php

namespace Tests\Feature;

use App\Business\Infrastructure\Persistence\Eloquent\Bodega;
use App\Business\Infrastructure\Persistence\Eloquent\Empresa;
use App\IAM\Application\Funcionalidades;
use App\IAM\Infrastructure\Persistence\Eloquent\Plan;
use App\IAM\Infrastructure\Persistence\Eloquent\Role;
use App\IAM\Infrastructure\Persistence\Eloquent\User;
use App\Operations\Infrastructure\Persistence\Eloquent\MovimientoInventario;
use App\Operations\Infrastructure\Persistence\Eloquent\Producto;
use App\Operations\Infrastructure\Persistence\Eloquent\Proveedor;
use App\Operations\Infrastructure\Persistence\Eloquent\StockBodega;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Acceso a recursos ajenos por ID (binding implícito de rutas).
 *
 * Cuando un usuario intenta leer o modificar por ID un recurso que pertenece
 * a otra empresa, el OwnerScope (global scope de Eloquent) no lo encuentra y
 * el binding implícito debe responder 404. Estas pruebas blindan ese
 * comportamiento para el módulo de inventario.
 *
 * A diferencia de las pruebas de validación (que cubren IDs enviados en el
 * cuerpo de la petición), aquí el recurso llega como PARTE DE LA RUTA.
 */
class InventarioRecursoAjenoTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->plan = Plan::create([
            'nombre' => 'Premium-Test',
            'precio_mensual' => 0,
            'limite_clientes' => 1000,
            'limite_citas' => 1000,
            'limite_facturas' => 1000,
            'funcionalidades' => array_keys(Funcionalidades::CATALOGO),
            'activo' => true,
            'orden' => 1,
        ]);
    }

    private function crearTenant(string $sufijo): array
    {
        // El usuario nace primero (sin empresa): el registro real hace lo
        // mismo porque empresas.owner_user_id es NOT NULL y apunta al usuario.
        $rol = Role::firstOrCreate(['nombre' => 'Administrador'], ['descripcion' => 'Administrador']);

        $user = User::factory()->create([
            'rol_id' => $rol->id,
            'estado' => 'ACTIVO',
            'activo' => true,
            'es_admin_empresa' => true,
            'plan_id' => $this->plan->id,
        ]);

        $empresa = Empresa::create([
            'nombre' => "Empresa {$sufijo}",
            'modo_cobro' => 'membresia',
            'membresia_vence_at' => now()->addMonth(),
            'estado' => 'ACTIVO',
            'activo' => true,
            'plan_id' => $this->plan->id,
            'owner_user_id' => $user->id,
        ]);

        $user->forceFill(['empresa_id' => $empresa->id])->save();

        $producto = Producto::create([
            'owner_id' => $user->id,
            'empresa_id' => $empresa->id,
            'nombre' => "Producto {$sufijo}",
            'sku' => "SKU-{$sufijo}",
            'precio_costo' => 1000,
            'precio_venta' => 2000,
            'is_service' => false,
            'activo' => true,
        ]);

        $bodega = Bodega::create([
            'owner_id' => $user->id,
            'empresa_id' => $empresa->id,
            'nombre' => "Bodega {$sufijo}",
            'direccion' => 'Calle 1',
            'responsable_id' => $user->id,
            'activo' => true,
            'es_principal' => true,
        ]);

        $proveedor = Proveedor::create([
            'owner_id' => $user->id,
            'empresa_id' => $empresa->id,
            'razon_social' => "Proveedor {$sufijo}",
            'tipo_documento' => 'NIT',
            'numero_documento' => '90000000' . substr(md5($sufijo), 0, 3),
        ]);

        $stock = StockBodega::create([
            'owner_id' => $user->id,
            'empresa_id' => $empresa->id,
            'producto_id' => $producto->id,
            'bodega_id' => $bodega->id,
            'cantidad' => 20,
            'costo_promedio' => 1000,
        ]);

        $movimiento = MovimientoInventario::create([
            'owner_id' => $user->id,
            'empresa_id' => $empresa->id,
            'producto_id' => $producto->id,
            'tipo' => 'ENTRADA',
            'bodega_destino_id' => $bodega->id,
            'cantidad' => 20,
            'costo_unitario' => 1000,
        ]);

        return compact('user', 'empresa', 'producto', 'bodega', 'proveedor', 'stock', 'movimiento');
    }

    public function test_no_puede_editar_stock_ajeno_por_id(): void
    {
        $a = $this->crearTenant('A');
        $b = $this->crearTenant('B');

        $this->actingAs($a['user'], 'sanctum')
            ->putJson("/api/inventario/stock/{$b['stock']->id}", [
                'cantidad' => 999,
            ])
            ->assertStatus(404);

        // El stock de B no fue tocado.
        $this->assertEqualsWithDelta(20, (float) $b['stock']->fresh()->cantidad, 0.001);
    }

    public function test_no_puede_eliminar_stock_ajeno_por_id(): void
    {
        $a = $this->crearTenant('A');
        $b = $this->crearTenant('B');

        $this->actingAs($a['user'], 'sanctum')
            ->deleteJson("/api/inventario/stock/{$b['stock']->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('stock_por_bodega', ['id' => $b['stock']->id]);
    }

    public function test_no_puede_eliminar_movimiento_ajeno_por_id(): void
    {
        $a = $this->crearTenant('A');
        $b = $this->crearTenant('B');

        $this->actingAs($a['user'], 'sanctum')
            ->deleteJson("/api/inventario/movimientos/{$b['movimiento']->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('movimientos_inventario', ['id' => $b['movimiento']->id]);
    }

    public function test_no_puede_ver_un_producto_ajeno_por_id(): void
    {
        $a = $this->crearTenant('A');
        $b = $this->crearTenant('B');

        $this->actingAs($a['user'], 'sanctum')
            ->getJson("/api/productos/{$b['producto']->id}")
            ->assertStatus(404);
    }

    public function test_no_puede_ver_una_orden_de_compra_ajena_por_id(): void
    {
        $a = $this->crearTenant('A');
        $b = $this->crearTenant('B');

        // Orden de compra de B creada directamente en BD.
        $orden = \App\Operations\Infrastructure\Persistence\Eloquent\OrdenCompra::create([
            'owner_id' => $b['user']->id,
            'empresa_id' => $b['empresa']->id,
            'proveedor_id' => $b['proveedor']->id,
            'usuario_id' => $b['user']->id,
            'bodega_id' => $b['bodega']->id,
            'fecha' => now()->toDateString(),
            'total' => 0,
            'estado' => 'BORRADOR',
        ]);

        $this->actingAs($a['user'], 'sanctum')
            ->getJson("/api/ordenes-compra/{$orden->id}")
            ->assertStatus(404);
    }

    public function test_no_puede_recibir_una_orden_de_compra_ajena(): void
    {
        $a = $this->crearTenant('A');
        $b = $this->crearTenant('B');

        $orden = \App\Operations\Infrastructure\Persistence\Eloquent\OrdenCompra::create([
            'owner_id' => $b['user']->id,
            'empresa_id' => $b['empresa']->id,
            'proveedor_id' => $b['proveedor']->id,
            'usuario_id' => $b['user']->id,
            'bodega_id' => $b['bodega']->id,
            'fecha' => now()->toDateString(),
            'total' => 0,
            'estado' => 'BORRADOR',
        ]);

        $this->actingAs($a['user'], 'sanctum')
            ->postJson("/api/ordenes-compra/{$orden->id}/recibir")
            ->assertStatus(404);

        // La orden de B sigue en borrador y no generó stock.
        $this->assertSame('BORRADOR', $orden->fresh()->estado);
    }

    public function test_un_superadmin_si_puede_acceder_a_recursos_de_otra_empresa(): void
    {
        $b = $this->crearTenant('B');

        $super = User::factory()->create([
            'estado' => 'ACTIVO',
            'activo' => true,
            'es_super_admin' => true,
        ]);

        // El superadmin (permisos globales) sí ve el producto de B.
        $this->actingAs($super, 'sanctum')
            ->getJson("/api/productos/{$b['producto']->id}")
            ->assertStatus(200);

        // Y el stock de B es visible en el listado.
        $stock = $this->actingAs($super, 'sanctum')
            ->getJson('/api/inventario/stock')
            ->assertStatus(200)
            ->json('data');

        $this->assertContains($b['producto']->id, array_column($stock, 'producto_id'));
    }
}
