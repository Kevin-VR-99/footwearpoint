<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class RevendedorDistribuidora extends Model
{
    use BelongsToTenant;
    protected $table = 'revendedor_distribuidora';

    protected $fillable = [
        'distribuidora_id',
        'revendedor_id',
        'codigo_interno',
        'estado',
        'fecha_alta',
        'notas',
    ];

    protected $casts = [
        'fecha_alta' => 'date',
    ];

    public function distribuidora()
    {
        return $this->belongsTo(Distribuidora::class, 'distribuidora_id');
    }

    public function revendedor()
    {
        return $this->belongsTo(Revendedor::class, 'revendedor_id');
    }
}