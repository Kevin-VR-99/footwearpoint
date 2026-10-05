<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Una temporada del catálogo (en pantalla se llama "temporada").
 *
 * Pertenece a UNA línea (D1): "Impuls Otoño-Invierno 2026" es una temporada de
 * la línea Impuls. Es del catálogo compartido, así que no tiene distribuidora
 * (TG-209).
 */
class Campana extends Model
{
    protected $table = 'campanas';

    protected $fillable = [
        'linea_id',
        'nombre',
        'descripcion',
        'fecha_inicio',
        'fecha_fin',
        'estado',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
    ];

    /**
     * Solo una temporada activa por línea (D7).
     *
     * Reemplaza el límite de "2 temporadas" que tenía la pantalla. Se revisa
     * aquí para dar un mensaje claro; además la base tiene su propio índice
     * único, por si algún día alguien cambia estados sin pasar por el modelo.
     */
    protected static function booted(): void
    {
        static::saving(function (self $campana) {
            if ($campana->estado !== 'activa') {
                return;
            }

            $yaHayOtra = static::query()
                ->where('linea_id', $campana->linea_id)
                ->where('estado', 'activa')
                ->when($campana->exists, fn ($consulta) => $consulta->whereKeyNot($campana->getKey()))
                ->exists();

            if ($yaHayOtra) {
                throw ValidationException::withMessages([
                    'estado' => ['Esta línea ya tiene una temporada activa. Cierra la anterior antes de activar otra.'],
                ]);
            }
        });
    }

    public function linea()
    {
        return $this->belongsTo(Linea::class, 'linea_id');
    }

    public function productosCampana()
    {
        return $this->hasMany(ProductoCampana::class, 'campana_id');
    }
}
