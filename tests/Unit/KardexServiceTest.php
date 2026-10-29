<?php

namespace Tests\Unit;

use App\Business\Infrastructure\Persistence\Eloquent\Bodega;
use App\Operations\Application\KardexService;
use App\Operations\Infrastructure\Persistence\Eloquent\MovimientoInventario;
use App\Operations\Infrastructure\Persistence\Eloquent\Producto;
use App\Operations\Infrastructure\Persistence\Eloquent\StockBodega;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Pruebas unitarias del KardexService: el corazón del inventario.
 *
 * Verifican el costeo por COSTO PROMEDIO PONDERADO y la consistencia del
 * stock bajo distintas operaciones (entrada, salida, traslado, reverso,
 * ajuste y eliminación). Son la red de seguridad que debe pasar siempre
 * antes de tocar la lógica de inventario.
 *
 * Se ejecutan sin autenticación: OwnerScope no filtra sin usuario, así que
 * el servicio opera de forma directa sobre el producto/bodega de prueba.
 */
class KardexServiceTest extends TestCase
{
    use RefreshDatabase;

    private KardexService $kardex;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kardex = app(KardexService::class);
    }

    /** Crea un producto y una bodega mínimos para operar el Kardex. */
    private function crearBase(): array
    {
        $producto = Producto::create([
            'nombre' => 'Filtro de aceite',
            'sku' => 'F-1',
            'precio_costo' => 100,
            'precio_venta' => 200,
            'is_service' => false,
            'activo' => true,
        ]);

        $bodega = Bodega::create([
            'nombre' => 'Principal',
            'direccion' => 'Calle 1',
            'activo' => true,
            'es_principal' => true,
        ]);

        return [$producto, $bodega];
    }

    /** Lee la fila de stock producto×bodega. */
    private function stock(Producto $p, Bodega $b): StockBodega
    {
        return StockBodega::where('producto_id', $p->id)
            ->where('bodega_id', $b->id)
            ->firstOrFail();
    }

    public function test_la_entrada_recalcula_el_costo_promedio_ponderado(): void
    {
        [$producto, $bodega] = $this->crearBase();

        // 10 unidades a $100 -> promedio 100.
        $this->kardex->entrada($producto->id, $bodega->id, 10, 100);
        $stock = $this->stock($producto, $bodega);
        $this->assertEqualsWithDelta(10, (float) $stock->cantidad, 0.001);
        $this->assertEqualsWithDelta(100, (float) $stock->costo_promedio, 0.001);

        // 10 unidades a $200 -> promedio (10*100 + 10*200) / 20 = 150.
        $this->kardex->entrada($producto->id, $bodega->id, 10, 200);
        $stock->refresh();
        $this->assertEqualsWithDelta(20, (float) $stock->cantidad, 0.001);
        $this->assertEqualsWithDelta(150, (float) $stock->costo_promedio, 0.001);
    }

    public function test_el_movimiento_de_entrada_registra_stock_y_costo_resultantes(): void
    {
        [$producto, $bodega] = $this->crearBase();

        $mov = $this->kardex->entrada($producto->id, $bodega->id, 10, 100);

        $this->assertSame('ENTRADA', $mov->tipo);
        $this->assertEqualsWithDelta(10, (float) $mov->cantidad, 0.001);
        $this->assertEqualsWithDelta(10, (float) $mov->stock_resultante, 0.001);
        $this->assertEqualsWithDelta(100, (float) $mov->costo_promedio_resultante, 0.001);
        $this->assertSame($bodega->id, $mov->bodega_destino_id);
    }

    public function test_la_salida_descuenta_stock_sin_alterar_el_costo_promedio(): void
    {
        [$producto, $bodega] = $this->crearBase();
        $this->kardex->entrada($producto->id, $bodega->id, 10, 100);
        $this->kardex->entrada($producto->id, $bodega->id, 10, 200); // 20 @ 150

        $mov = $this->kardex->salida($producto->id, $bodega->id, 5);

        $stock = $this->stock($producto, $bodega);
        $this->assertEqualsWithDelta(15, (float) $stock->cantidad, 0.001);
        // La salida se valora al costo vigente pero NO lo modifica.
        $this->assertEqualsWithDelta(150, (float) $stock->costo_promedio, 0.001);
        $this->assertEqualsWithDelta(150, (float) $mov->costo_unitario, 0.001);
        $this->assertSame('SALIDA', $mov->tipo);
    }

    public function test_la_salida_sin_stock_suficiente_lanza_excepcion(): void
    {
        [$producto, $bodega] = $this->crearBase();
        $this->kardex->entrada($producto->id, $bodega->id, 5, 100);

        $this->expectException(ValidationException::class);
        $this->kardex->salida($producto->id, $bodega->id, 100);
    }

    public function test_el_traslado_mueve_stock_entre_bodegas_respetando_el_costo(): void
    {
        [$producto, $origen] = $this->crearBase();
        $destino = Bodega::create(['nombre' => 'Sucursal Norte', 'direccion' => 'Calle 2', 'activo' => true]);

        $this->kardex->entrada($producto->id, $origen->id, 20, 150);
        $this->kardex->traslado($producto->id, $origen->id, $destino->id, 8);

        $enOrigen = $this->stock($producto, $origen);
        $enDestino = $this->stock($producto, $destino);

        $this->assertEqualsWithDelta(12, (float) $enOrigen->cantidad, 0.001);
        $this->assertEqualsWithDelta(8, (float) $enDestino->cantidad, 0.001);
        // Ambas bodegas quedan con el mismo costo trasladado.
        $this->assertEqualsWithDelta(150, (float) $enOrigen->costo_promedio, 0.001);
        $this->assertEqualsWithDelta(150, (float) $enDestino->costo_promedio, 0.001);
    }

    public function test_el_traslado_con_stock_insuficiente_lanza_excepcion(): void
    {
        [$producto, $origen] = $this->crearBase();
        $destino = Bodega::create(['nombre' => 'Sucursal Norte', 'direccion' => 'Calle 2', 'activo' => true]);
        $this->kardex->entrada($producto->id, $origen->id, 3, 100);

        $this->expectException(ValidationException::class);
        $this->kardex->traslado($producto->id, $origen->id, $destino->id, 10);
    }

    public function test_el_traslado_a_la_misma_bodega_lanza_excepcion(): void
    {
        [$producto, $bodega] = $this->crearBase();
        $this->kardex->entrada($producto->id, $bodega->id, 10, 100);

        $this->expectException(ValidationException::class);
        $this->kardex->traslado($producto->id, $bodega->id, $bodega->id, 1);
    }

    public function test_revertir_salida_devuelve_stock_sin_alterar_el_costo_promedio(): void
    {
        [$producto, $bodega] = $this->crearBase();
        $this->kardex->entrada($producto->id, $bodega->id, 10, 100);
        $this->kardex->entrada($producto->id, $bodega->id, 10, 200); // 20 @ 150
        $this->kardex->salida($producto->id, $bodega->id, 5);       // 15 @ 150

        $this->kardex->revertirSalida($producto->id, $bodega->id, 5);

        $stock = $this->stock($producto, $bodega);
        $this->assertEqualsWithDelta(20, (float) $stock->cantidad, 0.001);
        // El reverso entra al costo vigente: el promedio no se mueve.
        $this->assertEqualsWithDelta(150, (float) $stock->costo_promedio, 0.001);
    }

    public function test_ajustar_stock_al_alza_y_a_la_baja_registra_ajuste(): void
    {
        [$producto, $bodega] = $this->crearBase();
        $this->kardex->entrada($producto->id, $bodega->id, 10, 100);

        // Subir a 15 (delta +5): el costo promedio se mantiene en 100.
        $this->kardex->ajustarStock($producto->id, $bodega->id, 15, null);
        $stock = $this->stock($producto, $bodega);
        $this->assertEqualsWithDelta(15, (float) $stock->cantidad, 0.001);
        $this->assertEqualsWithDelta(100, (float) $stock->costo_promedio, 0.001);

        // Bajar a 8 (delta -7).
        $this->kardex->ajustarStock($producto->id, $bodega->id, 8, null);
        $stock->refresh();
        $this->assertEqualsWithDelta(8, (float) $stock->cantidad, 0.001);
        $this->assertEqualsWithDelta(100, (float) $stock->costo_promedio, 0.001);

        // Ambos ajustes quedaron registrados en el kardex.
        $this->assertSame(
            2,
            MovimientoInventario::where('motivo', 'AJUSTE_EDICION')->count()
        );
    }

    public function test_ajustar_stock_a_la_misma_cantidad_lanza_excepcion(): void
    {
        [$producto, $bodega] = $this->crearBase();
        $this->kardex->entrada($producto->id, $bodega->id, 10, 100);

        $this->expectException(ValidationException::class);
        $this->kardex->ajustarStock($producto->id, $bodega->id, 10, null);
    }

    public function test_eliminar_un_movimiento_manual_revierte_el_stock(): void
    {
        [$producto, $bodega] = $this->crearBase();
        $mov = $this->kardex->entrada($producto->id, $bodega->id, 10, 100);
        $this->assertEqualsWithDelta(10, (float) $this->stock($producto, $bodega)->cantidad, 0.001);

        $this->kardex->eliminar($mov);

        $this->assertEqualsWithDelta(0, (float) $this->stock($producto, $bodega)->cantidad, 0.001);
        $this->assertNull(MovimientoInventario::find($mov->id));
    }

    public function test_no_permite_eliminar_un_movimiento_con_referencia_automatica(): void
    {
        [$producto, $bodega] = $this->crearBase();
        // Movimiento generado por otro documento (ej. una orden de compra).
        $mov = $this->kardex->entrada(
            $producto->id,
            $bodega->id,
            10,
            100,
            null,
            'COMPRA',
            ['tipo' => 'OrdenCompra', 'id' => 1]
        );

        $this->expectException(ValidationException::class);
        $this->kardex->eliminar($mov);
    }

    public function test_eliminar_stock_registra_ajuste_y_borra_la_fila(): void
    {
        [$producto, $bodega] = $this->crearBase();
        $this->kardex->entrada($producto->id, $bodega->id, 10, 100);
        $stock = $this->stock($producto, $bodega);

        $this->kardex->eliminarStock($stock);

        // La fila desaparece...
        $this->assertDatabaseMissing('stock_por_bodega', [
            'producto_id' => $producto->id,
            'bodega_id' => $bodega->id,
        ]);
        // ...y la cantidad se dejó en cero con un ajuste trazable en el kardex.
        $this->assertSame(
            1,
            MovimientoInventario::where('motivo', 'AJUSTE_ELIMINACION')->count()
        );
    }
}
