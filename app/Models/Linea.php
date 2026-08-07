<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Linea extends Model
{
    use BelongsToTenant;

    protected $table = 'lineas';

    protected $fillable = [
        'distribuidora_id',
        'campana_id',
        'nombre',
        'descripcion',
        'activa',
    ];

    protected $casts = [
        'activa' => 'boolean',
    ];

    public function distribuidora()
    {
        return $this->belongsTo(Distribuidora::class, 'distribuidora_id');
    }

    public function campana()
    {
        return $this->belongsTo(Campana::class, 'campana_id');
    }

    public function marcas()
    {
        return $this->belongsToMany(Marca::class, 'linea_marca', 'linea_id', 'marca_id')
            ->withPivot('distribuidora_id')
            ->withTimestamps();
    }

    public function productos()
    {
        return $this->hasMany(Producto::class, 'linea_id');
    }
}