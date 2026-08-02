<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClienteDirecto extends Model
{
    protected $table = 'clientes_directos';

    protected $fillable = [
        'distribuidora_id',
        'usuario_id',
        'nombre',
        'telefono',
        'email',
        'direccion_contacto',
        'notas',
        'estado',
    ];

    public function distribuidora()
    {
        return $this->belongsTo(Distribuidora::class, 'distribuidora_id');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}