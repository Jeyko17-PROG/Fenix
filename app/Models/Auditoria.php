<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Auditoria extends Model
{
    protected $table = 'auditorias';

    protected $fillable = [
        'admin_id', 'usuario_id', 'bodega_id', 'accion', 'funcionalidad', 'estado_anterior', 'estado_nuevo',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class, 'bodega_id');
    }

    /** Registra una entrada de auditoría. */
    public static function registrar(?int $adminId, ?int $usuarioId, string $accion, ?string $funcionalidad, ?string $anterior, ?string $nuevo, ?int $bodegaId = null): void
    {
        static::create([
            'admin_id' => $adminId,
            'usuario_id' => $usuarioId,
            'bodega_id' => $bodegaId ?? auth()->user()?->bodega_id,
            'accion' => $accion,
            'funcionalidad' => $funcionalidad,
            'estado_anterior' => $anterior,
            'estado_nuevo' => $nuevo,
        ]);
    }
}
