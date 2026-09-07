<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAUsuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockBodega extends Model
{
    use PerteneceAUsuario;

    protected $table = 'stock_por_bodega';

    protected $fillable = [
        'owner_id',
        'producto_id',
        'bodega_id',
        'cantidad',
        'stock_minimo',
        'costo_promedio',
    ];

    protected $casts = [
        'cantidad' => 'decimal:4',
        'stock_minimo' => 'decimal:4',
        'costo_promedio' => 'decimal:4',
    ];

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class, 'bodega_id');
    }
}
