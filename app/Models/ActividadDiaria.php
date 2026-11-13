<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * TG-406 (A43) — Una fila por persona, día y canal. Nada más.
 *
 * No hay IP ni qué hizo, y no es un olvido: la política dice que esto solo
 * sirve para contar cuánta gente distinta usa la plataforma. Si algún día
 * hace falta saber quién hizo qué, eso es la auditoría, que es otra cosa y
 * tiene sus propias reglas.
 *
 * Sin timestamps: created_at sería la hora real y ya hay 'fecha', que es el
 * día en CDMX. Tener las dos invita a usar la hora para otra cosa.
 */
class ActividadDiaria extends Model
{
    public const ZONA = 'America/Mexico_City';

    /** Cuánto se guarda el detalle. */
    public const DIAS_QUE_SE_GUARDA = 90;

    public const CANAL_PANEL = 'panel';

    public const CANAL_APP = 'app';

    public const CANALES = [self::CANAL_PANEL, self::CANAL_APP];

    protected $table = 'actividad_diaria';

    public $timestamps = false;

    protected $fillable = ['usuario_id', 'fecha', 'canal'];

    protected $casts = ['fecha' => 'date'];

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    /** Lo que ya pasó de los 90 días y toca borrar. */
    public function scopeVencidas(Builder $consulta): Builder
    {
        return $consulta->whereDate('fecha', '<', now(self::ZONA)->subDays(self::DIAS_QUE_SE_GUARDA)->toDateString());
    }

    public function scopeDelCanal(Builder $consulta, ?string $canal): Builder
    {
        return $canal === null ? $consulta : $consulta->where('canal', $canal);
    }
}
