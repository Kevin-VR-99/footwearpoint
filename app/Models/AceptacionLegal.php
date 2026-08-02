<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AceptacionLegal extends Model
{
    protected $table = 'aceptaciones_legales';

    public $timestamps = false;

    protected $fillable = [
        'usuario_id',
        'tipo_documento',
        'version',
        'fecha_aceptacion',
        'ip_origen',
    ];

    protected $casts = [
        'fecha_aceptacion' => 'datetime',
    ];

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}