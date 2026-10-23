<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una línea del catálogo (Impuls, Confort, Cklass...).
 *
 * Del catálogo compartido: existe una sola vez en todo el sistema y solo la
 * administra el admin general (TG-209). Qué líneas vende cada distribuidora se
 * guarda aparte, en distribuidora_linea.
 */
class Linea extends Model
{
    protected $table = 'lineas';

    protected $fillable = [
        'nombre',
        'descripcion',
        'logotipo_url',
        'activa',
    ];

    protected $casts = [
        'activa' => 'boolean',
    ];

    /** Sus publicaciones, una por temporada global. */
    public function campanas()
    {
        return $this->hasMany(Campana::class, 'linea_id');
    }

    /** La campaña de la línea en la temporada vigente (por fecha); a lo más una. */
    public function campanaActiva()
    {
        return $this->hasOne(Campana::class, 'linea_id')->vigentes();
    }

    public function marcas()
    {
        return $this->belongsToMany(Marca::class, 'linea_marca', 'linea_id', 'marca_id')
            ->withTimestamps();
    }
}
