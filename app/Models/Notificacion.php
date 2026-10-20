<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class Notificacion extends Model
{
    use BelongsToTenant;
    protected $table = 'notificaciones';

    const UPDATED_AT = null;

    protected $fillable = [
        'usuario_id',
        'distribuidora_id',
        'tipo',
        'titulo',
        'mensaje',
        'leida_at',
        'entidad_tipo',
        'entidad_id',
    ];

    protected $casts = [
        'leida_at' => 'datetime',
    ];

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function distribuidora()
    {
        return $this->belongsTo(Distribuidora::class, 'distribuidora_id');
    }

    /** A dónde lleva el aviso al abrirlo, si lleva a algún lado. */
    public function enlace(): ?string
    {
        if ($this->entidad_tipo === 'importacion_catalogo' && $this->entidad_id !== null) {
            return $this->tipo === 'catalogo_ia_listo'
                ? route('admin.catalogos.revisar', ['importacion' => $this->entidad_id])
                : route('admin.catalogos');
        }

        return null;
    }
}
