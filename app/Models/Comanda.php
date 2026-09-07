<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAUsuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Comanda: el pedido abierto de una mesa. El mesero agrega ítems, la cocina
 * los prepara (KDS) y al cerrar se convierte en factura con medio de pago.
 */
class Comanda extends Model
{
    use PerteneceAUsuario;

    protected $table = 'comandas';

    protected $fillable = ['owner_id', 'empresa_id', 'mesa_id', 'user_id', 'estado', 'notas', 'factura_id'];

    public function mesa(): BelongsTo
    {
        return $this->belongsTo(Mesa::class, 'mesa_id');
    }

    public function mesero(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ComandaItem::class, 'comanda_id');
    }

    public function factura(): BelongsTo
    {
        return $this->belongsTo(Factura::class, 'factura_id');
    }

    public function total(): float
    {
        return (float) $this->items()->sum('subtotal');
    }
}
