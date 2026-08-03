<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class StockLocal extends Model
{
    use BelongsToTenant;
    protected $table = 'stock_local';

    // Esta tabla solo tiene updated_at, no created_at.
    const CREATED_AT = null;

    protected $fillable = [
        'distribuidora_id',
        'sucursal_id',
        'variante_id',
        'cantidad_disponible',
        'stock_minimo',
    ];

    public function distribuidora()
    {
        return $this->belongsTo(Distribuidora::class, 'distribuidora_id');
    }

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }

    public function variante()
    {
        return $this->belongsTo(Variante::class, 'variante_id');
    }

    public function movimientos()
    {
        return $this->hasMany(MovimientoStock::class, 'stock_local_id');
    }
}