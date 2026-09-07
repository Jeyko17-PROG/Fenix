<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAUsuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovimientoInventario extends Model
{
    use PerteneceAUsuario;

    protected $table = 'movimientos_inventario';

    protected $fillable = [
        'owner_id',
        'producto_id',
        'tipo',
        'motivo',
        'bodega_origen_id',
        'bodega_destino_id',
        'cantidad',
        'costo_unitario',
        'costo_promedio_resultante',
        'stock_resultante',
        'referencia_tipo',
        'referencia_id',
        'usuario_id',
    ];

    protected $casts = [
        'cantidad' => 'decimal:4',
        'costo_unitario' => 'decimal:4',
        'costo_promedio_resultante' => 'decimal:4',
        'stock_resultante' => 'decimal:4',
    ];

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function bodegaOrigen(): BelongsTo
    {
        return $this->belongsTo(Bodega::class, 'bodega_origen_id');
    }

    public function bodegaDestino(): BelongsTo
    {
        return $this->belongsTo(Bodega::class, 'bodega_destino_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
