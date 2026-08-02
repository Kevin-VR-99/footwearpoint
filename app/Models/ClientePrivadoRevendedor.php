<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientePrivadoRevendedor extends Model
{
    protected $table = 'clientes_privados_revendedor';

    protected $fillable = [
        'revendedor_id',
        'nombre',
        'telefono',
        'referencia',
        'notas',
    ];

    public function revendedor()
    {
        return $this->belongsTo(Revendedor::class, 'revendedor_id');
    }
}