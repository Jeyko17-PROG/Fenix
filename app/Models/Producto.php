<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAUsuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Producto extends Model
{
    use PerteneceAUsuario, SoftDeletes;

    protected $table = 'productos';

    protected $fillable = [
        'owner_id',
        'categoria_id',
        'sku',
        'codigo_barras',
        'nombre',
        'descripcion',
        'unidad_medida',
        'unidad_compra',
        'unidades_por_compra',
        'is_service',
        'has_commission',
        'commission_type',
        'commission_value',
        'precio_costo',
        'precio_venta',
        'imagen_url',
        'activo',
        'disponible',
        'created_by',
    ];

    protected $casts = [
        'precio_costo' => 'decimal:2',
        'precio_venta' => 'decimal:2',
        'unidades_por_compra' => 'decimal:4',
        'activo' => 'boolean',
        'disponible' => 'boolean',
        'is_service' => 'boolean',
        'has_commission' => 'boolean',
        'commission_value' => 'decimal:2',
    ];

    protected $appends = ['stock_total', 'salidas', 'valor_inventario'];

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    public function proveedores(): BelongsToMany
    {
        return $this->belongsToMany(Proveedor::class, 'producto_proveedor', 'producto_id', 'proveedor_id')
            ->withPivot('precio_compra_acordado')
            ->withTimestamps();
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(StockBodega::class, 'producto_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoInventario::class, 'producto_id');
    }

    /** Solo los movimientos de salida (venta, merma, servicio); para withSum() en listados. */
    public function movimientosSalida(): HasMany
    {
        return $this->hasMany(MovimientoInventario::class, 'producto_id')->where('tipo', 'SALIDA');
    }

    public function serviceOrderDetails(): HasMany
    {
        return $this->hasMany(ServiceOrderDetail::class, 'producto_id');
    }

    /** Galería de fotos adicionales del producto (aparte de imagen_url, la principal). */
    public function galeria(): MorphMany
    {
        return $this->morphMany(GaleriaImagen::class, 'imageable')->orderBy('orden');
    }

    /**
     * Stock total sumando todas las bodegas.
     */
    public function getStockTotalAttribute(): float
    {
        // Usa la relación ya cargada si existe para evitar consultas extra.
        if ($this->relationLoaded('stocks')) {
            return (float) $this->stocks->sum('cantidad');
        }
        return (float) $this->stocks()->sum('cantidad');
    }

    /**
     * Unidades egresadas (venta, merma o servicio): se calcula del kardex de
     * movimientos (tipo SALIDA), no de una columna aparte, para que nunca quede
     * desincronizado del historial real que ya registra KardexService.
     * Usa el resultado de withSum('movimientosSalida as salidas_sum', ...) si el
     * listado lo precargó (evita una consulta aparte por cada producto).
     */
    public function getSalidasAttribute(): float
    {
        if (array_key_exists('salidas_sum', $this->attributes)) {
            return (float) $this->attributes['salidas_sum'];
        }
        return (float) $this->movimientosSalida()->sum('cantidad');
    }

    /**
     * Valor total del inventario de este producto: stock actual x precio de costo.
     */
    public function getValorInventarioAttribute(): float
    {
        return round($this->stock_total * (float) $this->precio_costo, 2);
    }

    /**
     * Verifica si es un servicio (no descuenta stock).
     */
    public function esServicio(): bool
    {
        return (bool) $this->is_service;
    }

    /**
     * Retorna la comisión (si aplica) o null.
     */
    public function obtenerComision(): ?array
    {
        if (!$this->has_commission) {
            return null;
        }

        return [
            'tipo' => $this->commission_type,
            'valor' => $this->commission_value,
        ];
    }
}
