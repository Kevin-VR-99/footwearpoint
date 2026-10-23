<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * La publicación de UNA línea en UNA temporada global (tabla campanas).
 *
 * La temporada ya no es por línea: solo existen Primavera-Verano y
 * Otoño-Invierno de cada año, iguales para todas (App\Models\Temporada). Esta
 * fila solo amarra "qué productos publica la línea X en esa temporada", para
 * que el catálogo siga sabiendo de qué línea es cada producto publicado
 * (el mismo modelo puede salir en dos líneas, D5).
 *
 * Nunca se crea a mano: la crea App\Services\Catalogo\CampanaDeTemporada
 * cuando se importa un catálogo, con el mismo nombre y fechas de la
 * temporada. Qué se vende lo decide la FECHA (temporada vigente), no el
 * campo estado, que se conserva solo por historial.
 */
class Campana extends Model
{
    protected $table = 'campanas';

    protected $fillable = [
        'linea_id',
        'temporada_id',
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
     * Si alguien la crea sin temporada, entra a la vigente y toma su nombre
     * y fechas: nunca queda una campaña suelta.
     */
    protected static function booted(): void
    {
        static::creating(function (self $campana) {
            if ($campana->temporada_id === null) {
                $campana->temporada_id = Temporada::actual()->id;
            }

            $temporada = Temporada::find($campana->temporada_id);
            if ($temporada) {
                $campana->nombre ??= $temporada->nombre;
                $campana->fecha_inicio ??= $temporada->fecha_inicio;
                $campana->fecha_fin ??= $temporada->fecha_fin;
            }
        });
    }

    /** Solo las campañas de la temporada vigente hoy. */
    public function scopeVigentes(Builder $consulta): Builder
    {
        return $consulta->whereHas('temporada', fn (Builder $t) => $t->vigente());
    }

    public function temporada()
    {
        return $this->belongsTo(Temporada::class, 'temporada_id');
    }

    public function linea()
    {
        return $this->belongsTo(Linea::class, 'linea_id');
    }

    public function productosCampana()
    {
        return $this->hasMany(ProductoCampana::class, 'campana_id');
    }

    /**
     * Estado calculado por fecha, con los mismos valores que antes para que
     * la API no cambie: 'activa' (vigente), 'borrador' (próxima) o
     * 'finalizada' (pasada).
     */
    public function estadoPorFecha(): string
    {
        $temporada = $this->temporada;

        if (! $temporada) {
            return (string) $this->estado;
        }

        return ['actual' => 'activa', 'proxima' => 'borrador', 'pasada' => 'finalizada'][$temporada->vigencia()];
    }
}
