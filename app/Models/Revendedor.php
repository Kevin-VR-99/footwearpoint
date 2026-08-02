<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Revendedor extends Model
{
    protected $table = 'revendedores';

    protected $fillable = [
        'usuario_id',
        'nombre',
        'telefono',
        'email',
        'estado',
    ];

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function afiliaciones()
    {
        return $this->hasMany(RevendedorDistribuidora::class, 'revendedor_id');
    }

    public function clientesPrivados()
    {
        return $this->hasMany(ClientePrivadoRevendedor::class, 'revendedor_id');
    }
}