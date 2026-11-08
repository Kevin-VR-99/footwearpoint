<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** TG-356: unidades de una partida de pedido cubiertas por una nota de devolución. */
class ValeDevolucionPartida extends Model
{
    use BelongsToTenant;

    protected $table = 'vale_devolucion_partidas';

    public const UPDATED_AT = null;

    protected $fillable = ['distribuidora_id', 'vale_id', 'pedido_detalle_id', 'cantidad', 'precio_unitario', 'monto'];

    protected $casts = ['precio_unitario' => 'decimal:2', 'monto' => 'decimal:2', 'cantidad' => 'integer'];

    public function vale()
    {
        return $this->belongsTo(Vale::class, 'vale_id');
    }

    public function pedidoDetalle()
    {
        return $this->belongsTo(PedidoDetalle::class, 'pedido_detalle_id');
    }
}
