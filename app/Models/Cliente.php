<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAUsuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cliente extends Model
{
    use PerteneceAUsuario, SoftDeletes;

    protected $table = 'clientes';

    protected $fillable = [
        'owner_id',
        'user_id',
        'nombre_completo',
        'tipo_documento',
        'numero_documento',
        'email',
        'telefono',
        'direccion',
        'estado',
        'seguimiento_comercial',
        'created_by',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Las siguientes relaciones se conectan en bloques posteriores
    // (citas → Bloque B, facturas → Bloque D, notas → Bloque E).
    public function citas()
    {
        return $this->hasMany(Cita::class, 'cliente_id');
    }

    public function facturas()
    {
        return $this->hasMany(Factura::class, 'cliente_id');
    }

    public function notas()
    {
        return $this->hasMany(Nota::class, 'cliente_id');
    }
}
