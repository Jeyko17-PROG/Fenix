<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ítem de una comanda con su estado en cocina (PENDIENTE→PREPARANDO→LISTO→ENTREGADO). */
class ComandaItem extends Model
{
    protected $table = 'comanda_items';

    protected $fillable = ['comanda_id', 'producto_id', 'descripcion', 'cantidad', 'precio_unitario', 'subtotal', 'estado_cocina', 'notas'];

    protected $casts = [
        'cantidad' => 'decimal:2',
        'precio_unitario' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    public function comanda(): BelongsTo
    {
        return $this->belongsTo(Comanda::class, 'comanda_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }
}
