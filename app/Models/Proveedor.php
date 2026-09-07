<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAUsuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Proveedor extends Model
{
    use PerteneceAUsuario, SoftDeletes;

    protected $table = 'proveedores';

    protected $fillable = [
        'owner_id',
        'razon_social',
        'tipo_documento',
        'numero_documento',
        'digito_verificacion',
        'email',
        'telefono',
        'direccion',
        'terminos_pago',
        'created_by',
    ];

    public function productos(): BelongsToMany
    {
        return $this->belongsToMany(Producto::class, 'producto_proveedor', 'proveedor_id', 'producto_id')
            ->withPivot('precio_compra_acordado')
            ->withTimestamps();
    }
}
