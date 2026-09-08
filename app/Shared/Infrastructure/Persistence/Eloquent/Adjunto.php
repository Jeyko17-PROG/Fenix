<?php

namespace App\Shared\Infrastructure\Persistence\Eloquent;

use App\IAM\Infrastructure\Persistence\Eloquent\User;
use App\Shared\Infrastructure\Persistence\Concerns\PerteneceAUsuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Adjunto extends Model
{
    use PerteneceAUsuario;

    protected $table = 'adjuntos';

    protected $fillable = [
        'owner_id', 'adjuntable_tipo', 'adjuntable_id',
        'categoria', 'nombre', 'ruta', 'url', 'tipo_mime', 'tamano_bytes', 'created_by',
    ];

    protected $casts = [
        'tamano_bytes' => 'integer',
    ];

    public function adjuntable(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'adjuntable_tipo', 'adjuntable_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
