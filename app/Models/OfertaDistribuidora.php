<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Lo que una distribuidora cambió de un producto del catálogo (TG-212):
 * su propio precio de mayoreo, o esconderlo de sus clientes.
 *
 * Si no hay fila, no cambió nada: el producto se ve y su mayoreo sale del
 * descuento general de la distribuidora.
 */
class OfertaDistribuidora extends Model
{
    use BelongsToTenant;

    protected $table = 'ofertas_distribuidora';

    protected $fillable = [
        'distribuidora_id',
        'producto_campana_id',
        'precio_mayorista',
        'publicado',
    ];

    protected $casts = [
        'precio_mayorista' => 'decimal:2',
        'publicado' => 'boolean',
    ];

    public function productoCampana()
    {
        return $this->belongsTo(ProductoCampana::class, 'producto_campana_id');
    }

    /** Sin precio propio y visible: es igual a no tener fila. */
    public function esIgualALoNormal(): bool
    {
        return $this->precio_mayorista === null && $this->publicado;
    }
}
