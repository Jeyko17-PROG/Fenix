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
 * Aislamiento multiempresa del módulo de inventario.
 *
 * Dos empresas distintas (A y B), cada una con su usuario administrador,
 * producto, bodega y proveedor propios. Se comprueba que un usuario normal
 * de la empresa A NO pueda referenciar recursos de la empresa B en las
 * operaciones de escritura de inventario.
 *
 * Nota: estas pruebas documentan el COMPORTAMIENTO ESPERADO (seguro). Si el
 * sistema actualmente permite el cruce, fallarán y señalarán la brecha que
 * debe corregirse en una etapa posterior.
 */
class InventarioSeguridadEmpresaTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        // Plan con todas las funcionalidades para que ningún middleware de
        // feature bloquee las rutas de inventario/productos/proveedores.
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

    /** Crea una empresa con su usuario administrador y sus recursos de inventario. */
    private function crearTenant(string $sufijo): array
    {
        // El usuario nace primero (sin empresa): el registro real hace lo
        // mismo porque empresas.owner_user_id es NOT NULL y apunta al usuario.
        $rol = Role::firstOrCreate(
            ['nombre' => 'Administrador'],
            ['descripcion' => 'Administrador']
        );

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

        return compact('user', 'empresa', 'producto', 'bodega', 'proveedor');
    }

    public function test_no_permite_registrar_movimiento_con_producto_de_otra_empresa(): void
    {
        $a = $this->crearTenant('A');
        $b = $this->crearTenant('B');

        $respuesta = $this->actingAs($a['user'], 'sanctum')
            ->postJson('/api/inventario/movimientos', [
                'tipo' => 'ENTRADA',
                'producto_id' => $b['producto']->id,   // <- recurso ajeno
                'cantidad' => 5,
                'bodega_destino_id' => $a['bodega']->id,
                'costo_unitario' => 1000,
            ]);

        $this->assertNotSame(
            201,
            $respuesta->getStatusCode(),
            'Un usuario no debe poder registrar un movimiento sobre un producto de otra empresa.'
        );
        $this->assertDatabaseMissing('movimientos_inventario', [
            'producto_id' => $b['producto']->id,
        ]);
    }

    public function test_no_permite_registrar_traslado_hacia_bodega_de_otra_empresa(): void
    {
        $a = $this->crearTenant('A');
        $b = $this->crearTenant('B');

        $respuesta = $this->actingAs($a['user'], 'sanctum')
            ->postJson('/api/inventario/movimientos', [
                'tipo' => 'TRASLADO',
                'producto_id' => $a['producto']->id,
                'cantidad' => 1,
                'bodega_origen_id' => $a['bodega']->id,
                'bodega_destino_id' => $b['bodega']->id,  // <- recurso ajeno
            ]);

        $this->assertNotSame(
            201,
            $respuesta->getStatusCode(),
            'Un usuario no debe poder trasladar stock hacia una bodega de otra empresa.'
        );
    }

    public function test_no_permite_definir_minimo_en_producto_de_otra_empresa(): void
    {
        $a = $this->crearTenant('A');
        $b = $this->crearTenant('B');

        $respuesta = $this->actingAs($a['user'], 'sanctum')
            ->postJson('/api/inventario/minimo', [
                'producto_id' => $b['producto']->id,   // <- recurso ajeno
                'bodega_id' => $a['bodega']->id,
                'stock_minimo' => 5,
            ]);

        $this->assertNotSame(
            200,
            $respuesta->getStatusCode(),
            'Un usuario no debe poder definir el mínimo de un producto de otra empresa.'
        );
        $this->assertDatabaseMissing('stock_por_bodega', [
            'producto_id' => $b['producto']->id,
        ]);
    }

    public function test_no_permite_crear_orden_con_proveedor_de_otra_empresa(): void
    {
        $a = $this->crearTenant('A');
        $b = $this->crearTenant('B');

        $respuesta = $this->actingAs($a['user'], 'sanctum')
            ->postJson('/api/ordenes-compra', [
                'proveedor_id' => $b['proveedor']->id,  // <- recurso ajeno
                'bodega_id' => $a['bodega']->id,
                'fecha' => now()->toDateString(),
                'lineas' => [[
                    'producto_id' => $a['producto']->id,
                    'cantidad' => 5,
                    'precio_unitario' => 1000,
                ]],
            ]);

        $this->assertNotSame(
            201,
            $respuesta->getStatusCode(),
            'Un usuario no debe poder crear una orden de compra con un proveedor de otra empresa.'
        );
    }

    public function test_no_permite_crear_orden_con_producto_de_otra_empresa(): void
    {
        $a = $this->crearTenant('A');
        $b = $this->crearTenant('B');

        $respuesta = $this->actingAs($a['user'], 'sanctum')
            ->postJson('/api/ordenes-compra', [
                'proveedor_id' => $a['proveedor']->id,
                'bodega_id' => $a['bodega']->id,
                'fecha' => now()->toDateString(),
                'lineas' => [[
                    'producto_id' => $b['producto']->id,  // <- recurso ajeno
                    'cantidad' => 5,
                    'precio_unitario' => 1000,
                ]],
            ]);

        $this->assertNotSame(
            201,
            $respuesta->getStatusCode(),
            'Un usuario no debe poder crear una orden de compra con un producto de otra empresa.'
        );
    }

    public function test_no_permite_crear_orden_con_bodega_de_otra_empresa(): void
    {
        $a = $this->crearTenant('A');
        $b = $this->crearTenant('B');

        $respuesta = $this->actingAs($a['user'], 'sanctum')
            ->postJson('/api/ordenes-compra', [
                'proveedor_id' => $a['proveedor']->id,
                'bodega_id' => $b['bodega']->id,  // <- recurso ajeno
                'fecha' => now()->toDateString(),
                'lineas' => [[
                    'producto_id' => $a['producto']->id,
                    'cantidad' => 5,
                    'precio_unitario' => 1000,
                ]],
            ]);

        $this->assertNotSame(
            201,
            $respuesta->getStatusCode(),
            'Un usuario no debe poder crear una orden de compra con una bodega de otra empresa.'
        );
    }

    public function test_un_usuario_no_ve_stock_ni_movimientos_de_otra_empresa(): void
    {
        $a = $this->crearTenant('A');
        $b = $this->crearTenant('B');

        // Bodega de B con stock propio.
        StockBodega::create([
            'owner_id' => $b['user']->id,
            'empresa_id' => $b['empresa']->id,
            'producto_id' => $b['producto']->id,
            'bodega_id' => $b['bodega']->id,
            'cantidad' => 50,
            'costo_promedio' => 1000,
        ]);
        MovimientoInventario::create([
            'owner_id' => $b['user']->id,
            'empresa_id' => $b['empresa']->id,
            'producto_id' => $b['producto']->id,
            'tipo' => 'ENTRADA',
            'bodega_destino_id' => $b['bodega']->id,
            'cantidad' => 50,
            'costo_unitario' => 1000,
        ]);

        $this->actingAs($a['user'], 'sanctum');

        // Listados de stock: no deben contener filas de la empresa B.
        $stock = $this->getJson('/api/inventario/stock')->assertStatus(200)->json('data');
        $idsProductos = array_column($stock, 'producto_id');
        $this->assertNotContains($b['producto']->id, $idsProductos);

        // Movimientos: no deben contener filas de la empresa B.
        $movs = $this->getJson('/api/inventario/movimientos')->assertStatus(200)->json('data');
        $idsProductosMov = array_column($movs, 'producto_id');
        $this->assertNotContains($b['producto']->id, $idsProductosMov);
    }

    public function test_un_usuario_no_ve_el_catalogo_completo_de_productos_de_otra_empresa(): void
    {
        $a = $this->crearTenant('A');
        $b = $this->crearTenant('B');

        $this->actingAs($a['user'], 'sanctum');

        $productos = $this->getJson('/api/productos?simple=1')->assertStatus(200)->json('data');
        $ids = array_column($productos, 'id');

        $this->assertContains($a['producto']->id, $ids);
        $this->assertNotContains($b['producto']->id, $ids);
    }
}
