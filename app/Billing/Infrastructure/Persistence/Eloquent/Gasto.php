<?php

namespace App\Billing\Infrastructure\Persistence\Eloquent;

use App\IAM\Infrastructure\Persistence\Eloquent\User;
use App\Shared\Infrastructure\Persistence\Concerns\PerteneceAUsuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Gasto extends Model
{
    use PerteneceAUsuario, SoftDeletes;

    protected $table = 'gastos';

    protected $fillable = [
        'owner_id',
        'user_id',
        'caja_sesion_id',
        'bodega_id',
        'categoria',
        'descripcion',
        'monto',
        'fecha',
    ];

    protected $casts = [
        'monto' => 'decimal:2',
        'fecha' => 'date',
    ];

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function cajaSesion(): BelongsTo
    {
        return $this->belongsTo(CajaSesion::class, 'caja_sesion_id');
    }
}
