<?php

namespace App\Services;

use App\Models\MovimientoInventario;
use App\Models\StockBodega;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Servicio central del Kardex.
 *
 * Reglas:
 *  - El stock vive en stock_por_bodega (producto × bodega), nunca se edita a mano.
 *  - Costeo por COSTO PROMEDIO PONDERADO: se recalcula en cada ENTRADA.
 *  - Las SALIDAS se valoran al costo promedio vigente (no lo modifican).
 *  - Todo ocurre dentro de una transacción para mantener consistencia.
 */
class KardexService
{
    /**
     * Registra una ENTRADA (compra, devolución, ajuste positivo) en una bodega.
     */
    public function entrada(int $productoId, int $bodegaId, float $cantidad, float $costoUnitario, ?int $usuarioId = null, string $motivo = 'COMPRA', array $referencia = []): MovimientoInventario
    {
        $this->validarCantidadPositiva($cantidad);

        return DB::transaction(function () use ($productoId, $bodegaId, $cantidad, $costoUnitario, $usuarioId, $motivo, $referencia) {
            $stock = $this->lockStock($productoId, $bodegaId);

            $cantidadActual = (float) $stock->cantidad;
            $costoActual = (float) $stock->costo_promedio;

            // Costo promedio ponderado.
            $nuevaCantidad = $cantidadActual + $cantidad;
            $nuevoCosto = $nuevaCantidad > 0
                ? (($cantidadActual * $costoActual) + ($cantidad * $costoUnitario)) / $nuevaCantidad
                : $costoUnitario;

            $stock->cantidad = $nuevaCantidad;
            $stock->costo_promedio = $nuevoCosto;
            $stock->save();

            return $this->registrar([
                'producto_id' => $productoId,
                'tipo' => 'ENTRADA',
                'motivo' => $motivo,
                'bodega_destino_id' => $bodegaId,
                'cantidad' => $cantidad,
                'costo_unitario' => $costoUnitario,
                'costo_promedio_resultante' => $nuevoCosto,
                'stock_resultante' => $nuevaCantidad,
                'usuario_id' => $usuarioId,
            ], $referencia);
        });
    }

    /**
     * Revierte una SALIDA anterior (ej. al eliminar una factura o reducir sus
     * cantidades): devuelve la cantidad a la bodega SIN alterar el costo
     * promedio. Internamente es una ENTRADA valorada al costo promedio
     * vigente — matemáticamente eso deja el promedio ponderado intacto (dar
     * de alta N unidades al mismo costo que ya tenían no puede moverlo) — a
     * diferencia de entrada() a secas, que sí recalcula el promedio con el
     * costo que se le pase.
     */
    public function revertirSalida(int $productoId, int $bodegaId, float $cantidad, ?int $usuarioId = null, string $motivo = 'REVERSO', array $referencia = []): MovimientoInventario
    {
        $costoActual = (float) (StockBodega::where('producto_id', $productoId)->where('bodega_id', $bodegaId)->value('costo_promedio') ?? 0);

        return $this->entrada($productoId, $bodegaId, $cantidad, $costoActual, $usuarioId, $motivo, $referencia);
    }

    /**
     * Registra una SALIDA (venta, pérdida, ajuste negativo) desde una bodega.
     */
    public function salida(int $productoId, int $bodegaId, float $cantidad, ?int $usuarioId = null, string $motivo = 'VENTA', array $referencia = []): MovimientoInventario
    {
        $this->validarCantidadPositiva($cantidad);

        return DB::transaction(function () use ($productoId, $bodegaId, $cantidad, $usuarioId, $motivo, $referencia) {
            $stock = $this->lockStock($productoId, $bodegaId);

            if ((float) $stock->cantidad < $cantidad) {
                throw ValidationException::withMessages([
                    'cantidad' => ["Stock insuficiente en la bodega (disponible: {$stock->cantidad})."],
                ]);
            }

            $costo = (float) $stock->costo_promedio; // las salidas no alteran el promedio
            $stock->cantidad = (float) $stock->cantidad - $cantidad;
            $stock->save();

            return $this->registrar([
                'producto_id' => $productoId,
                'tipo' => 'SALIDA',
                'motivo' => $motivo,
                'bodega_origen_id' => $bodegaId,
                'cantidad' => $cantidad,
                'costo_unitario' => $costo,
                'costo_promedio_resultante' => $costo,
                'stock_resultante' => $stock->cantidad,
                'usuario_id' => $usuarioId,
            ], $referencia);
        });
    }

    /**
     * Traslada stock entre dos bodegas (salida en origen + entrada en destino).
     */
    public function traslado(int $productoId, int $bodegaOrigenId, int $bodegaDestinoId, float $cantidad, ?int $usuarioId = null): MovimientoInventario
    {
        $this->validarCantidadPositiva($cantidad);

        if ($bodegaOrigenId === $bodegaDestinoId) {
            throw ValidationException::withMessages([
                'bodega_destino_id' => ['La bodega de origen y destino no pueden ser la misma.'],
            ]);
        }

        return DB::transaction(function () use ($productoId, $bodegaOrigenId, $bodegaDestinoId, $cantidad, $usuarioId) {
            $origen = $this->lockStock($productoId, $bodegaOrigenId);

            if ((float) $origen->cantidad < $cantidad) {
                throw ValidationException::withMessages([
                    'cantidad' => ["Stock insuficiente en la bodega de origen (disponible: {$origen->cantidad})."],
                ]);
            }

            $costoTraslado = (float) $origen->costo_promedio;

            // Salida en origen.
            $origen->cantidad = (float) $origen->cantidad - $cantidad;
            $origen->save();

            // Entrada en destino (recalcula su promedio con el costo trasladado).
            $destino = $this->lockStock($productoId, $bodegaDestinoId);
            $cantDestino = (float) $destino->cantidad;
            $costoDestino = (float) $destino->costo_promedio;
            $nuevaCantDestino = $cantDestino + $cantidad;
            $nuevoCostoDestino = $nuevaCantDestino > 0
                ? (($cantDestino * $costoDestino) + ($cantidad * $costoTraslado)) / $nuevaCantDestino
                : $costoTraslado;
            $destino->cantidad = $nuevaCantDestino;
            $destino->costo_promedio = $nuevoCostoDestino;
            $destino->save();

            return $this->registrar([
                'producto_id' => $productoId,
                'tipo' => 'TRASLADO',
                'motivo' => 'TRASLADO',
                'bodega_origen_id' => $bodegaOrigenId,
                'bodega_destino_id' => $bodegaDestinoId,
                'cantidad' => $cantidad,
                'costo_unitario' => $costoTraslado,
                'costo_promedio_resultante' => $nuevoCostoDestino,
                'stock_resultante' => $nuevaCantDestino,
                'usuario_id' => $usuarioId,
            ], []);
        });
    }

    /**
     * Elimina un movimiento cargado a mano desde Inventario (sin referencia a
     * otro documento, ej. una factura) y revierte su efecto en el stock.
     *
     * Solo ajusta la CANTIDAD; el costo promedio no se reconstruye hacia
     * atrás (no es invertible con precisión si hubo movimientos posteriores
     * al que se está borrando) — mismo criterio que ya usan las SALIDAs, que
     * tampoco tocan el costo promedio.
     */
    public function eliminar(MovimientoInventario $movimiento): void
    {
        if ($movimiento->referencia_tipo) {
            throw ValidationException::withMessages([
                'movimiento' => ["Este movimiento lo generó {$movimiento->referencia_tipo} automáticamente; no se puede eliminar desde aquí."],
            ]);
        }

        DB::transaction(function () use ($movimiento) {
            match ($movimiento->tipo) {
                'ENTRADA' => $this->ajustarCantidad($movimiento->producto_id, $movimiento->bodega_destino_id, -(float) $movimiento->cantidad),
                'SALIDA' => $this->ajustarCantidad($movimiento->producto_id, $movimiento->bodega_origen_id, (float) $movimiento->cantidad),
                'TRASLADO' => (function () use ($movimiento) {
                    $this->ajustarCantidad($movimiento->producto_id, $movimiento->bodega_origen_id, (float) $movimiento->cantidad);
                    $this->ajustarCantidad($movimiento->producto_id, $movimiento->bodega_destino_id, -(float) $movimiento->cantidad);
                })(),
                default => null,
            };

            $movimiento->delete();
        });
    }

    /**
     * Elimina una fila de stock (producto × bodega) que se creó por error o
     * que quedó huérfana (ej. el producto ya se borró). Si todavía tiene
     * cantidad, primero se registra una SALIDA de ajuste que la deja en cero
     * -conserva el rastro en el Kardex- y recién ahí se borra la fila; no es
     * un edit-a-mano silencioso de la cantidad.
     */
    public function eliminarStock(StockBodega $stock, ?int $usuarioId = null): void
    {
        DB::transaction(function () use ($stock, $usuarioId) {
            // Relee con lockForUpdate() en vez de confiar en la instancia que
            // llegó del controller (cargada fuera de la transacción): otra
            // venta/traslado concurrente pudo haber cambiado la cantidad
            // real entre ese momento y este.
            $bloqueado = $this->lockStock($stock->producto_id, $stock->bodega_id);

            if ((float) $bloqueado->cantidad > 0) {
                $this->registrar([
                    'producto_id' => $bloqueado->producto_id,
                    'tipo' => 'SALIDA',
                    'motivo' => 'AJUSTE_ELIMINACION',
                    'bodega_origen_id' => $bloqueado->bodega_id,
                    'cantidad' => (float) $bloqueado->cantidad,
                    'costo_unitario' => (float) $bloqueado->costo_promedio,
                    'costo_promedio_resultante' => (float) $bloqueado->costo_promedio,
                    'stock_resultante' => 0,
                    'usuario_id' => $usuarioId,
                ], []);
            }

            $bloqueado->delete();
        });
    }

    /**
     * Edita directamente la cantidad de una fila de stock (corrige un error
     * de carga) y registra el ajuste en el Kardex como un ENTRADA/SALIDA
     * normal (motivo AJUSTE_EDICION), para que quede en "Movimientos
     * recientes" igual que cualquier otro movimiento manual.
     */
    public function ajustarStock(int $productoId, int $bodegaId, float $nuevaCantidad, ?float $costoUnitario, ?int $usuarioId = null): MovimientoInventario
    {
        if ($nuevaCantidad < 0) {
            throw ValidationException::withMessages([
                'cantidad' => ['La cantidad no puede ser negativa.'],
            ]);
        }

        return DB::transaction(function () use ($productoId, $bodegaId, $nuevaCantidad, $costoUnitario, $usuarioId) {
            $stock = $this->lockStock($productoId, $bodegaId);
            $actual = (float) $stock->cantidad;
            $delta = $nuevaCantidad - $actual;

            if (abs($delta) < 0.0001) {
                throw ValidationException::withMessages([
                    'cantidad' => ['Esa ya es la cantidad actual.'],
                ]);
            }

            if ($delta > 0) {
                $costoActual = (float) $stock->costo_promedio;
                $costo = $costoUnitario ?? $costoActual;
                $nuevoCosto = $nuevaCantidad > 0
                    ? (($actual * $costoActual) + ($delta * $costo)) / $nuevaCantidad
                    : $costo;
                $stock->cantidad = $nuevaCantidad;
                $stock->costo_promedio = $nuevoCosto;
                $stock->save();

                return $this->registrar([
                    'producto_id' => $productoId,
                    'tipo' => 'ENTRADA',
                    'motivo' => 'AJUSTE_EDICION',
                    'bodega_destino_id' => $bodegaId,
                    'cantidad' => $delta,
                    'costo_unitario' => $costo,
                    'costo_promedio_resultante' => $nuevoCosto,
                    'stock_resultante' => $nuevaCantidad,
                    'usuario_id' => $usuarioId,
                ], []);
            }

            $costoActual = (float) $stock->costo_promedio; // las salidas no alteran el promedio
            $stock->cantidad = $nuevaCantidad;
            $stock->save();

            return $this->registrar([
                'producto_id' => $productoId,
                'tipo' => 'SALIDA',
                'motivo' => 'AJUSTE_EDICION',
                'bodega_origen_id' => $bodegaId,
                'cantidad' => abs($delta),
                'costo_unitario' => $costoActual,
                'costo_promedio_resultante' => $costoActual,
                'stock_resultante' => $nuevaCantidad,
                'usuario_id' => $usuarioId,
            ], []);
        });
    }

    /** Aplica un ajuste de cantidad (+/-) a una fila de stock, sin dejarla negativa. */
    private function ajustarCantidad(int $productoId, int $bodegaId, float $delta): void
    {
        $stock = $this->lockStock($productoId, $bodegaId);
        $resultante = (float) $stock->cantidad + $delta;

        if ($resultante < 0) {
            throw ValidationException::withMessages([
                'movimiento' => ['No se puede eliminar: dejaría el stock en negativo (es probable que ya se haya vendido o movido parte de esa cantidad).'],
            ]);
        }

        $stock->cantidad = $resultante;
        $stock->save();
    }

    /**
     * Obtiene (o crea) y bloquea la fila de stock producto×bodega.
     */
    private function lockStock(int $productoId, int $bodegaId): StockBodega
    {
        StockBodega::firstOrCreate(
            ['producto_id' => $productoId, 'bodega_id' => $bodegaId],
            ['cantidad' => 0, 'stock_minimo' => 0, 'costo_promedio' => 0]
        );

        return StockBodega::where('producto_id', $productoId)
            ->where('bodega_id', $bodegaId)
            ->lockForUpdate()
            ->first();
    }

    private function registrar(array $datos, array $referencia): MovimientoInventario
    {
        if (! empty($referencia)) {
            $datos['referencia_tipo'] = $referencia['tipo'] ?? null;
            $datos['referencia_id'] = $referencia['id'] ?? null;
        }

        return MovimientoInventario::create($datos);
    }

    private function validarCantidadPositiva(float $cantidad): void
    {
        if ($cantidad <= 0) {
            throw ValidationException::withMessages([
                'cantidad' => ['La cantidad debe ser mayor a cero.'],
            ]);
        }
    }
}
