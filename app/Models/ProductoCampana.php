<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class ProductoCampana extends Model
{
    use BelongsToTenant;
    protected $table = 'producto_campana';

    protected $fillable = [
        'distribuidora_id',
        'producto_id',
        'campana_id',
        'codigo_catalogo',
        'precio_mayorista',
        'precio_minorista_sugerido',
        'estado_disponibilidad',
        'publicado',
    ];

    protected $casts = [
        'publicado' => 'boolean',
        'precio_mayorista' => 'decimal:2',
        'precio_minorista_sugerido' => 'decimal:2',
    ];

    public function distribuidora()
    {
        return $this->belongsTo(Distribuidora::class, 'distribuidora_id');
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function campana()
    {
        return $this->belongsTo(Campana::class, 'campana_id');
    }

    public function imagenes()
    {
        return $this->hasMany(ProductoImagen::class, 'producto_campana_id');
    }

    public function disponibilidadPorVariante()
    {
        return $this->hasMany(DisponibilidadVarianteCampana::class, 'producto_campana_id');
    }
}