<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Combinación de filtros guardada con nombre por un usuario en una pantalla. */
class FiltroFavorito extends Model
{
    use BelongsToTenant;

    protected $table = 'filtros_favoritos';

    protected $fillable = ['distribuidora_id', 'usuario_id', 'pantalla', 'nombre', 'filtros'];

    protected $casts = ['filtros' => 'array'];

    /** Solo los del usuario en sesión (el tenant ya lo pone el scope global). */
    public function scopeDelUsuario(Builder $q, int $usuarioId, string $pantalla = 'reportes'): Builder
    {
        return $q->where('usuario_id', $usuarioId)->where('pantalla', $pantalla);
    }
}
