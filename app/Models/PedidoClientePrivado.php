<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PedidoClientePrivado extends Model
{
    protected $table = 'pedido_cliente_privado';

    // Tabla sin columna "id": su llave es la combinación de
    // pedido_detalle_id + cliente_privado_id.
    protected $primaryKey = null;
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'pedido_detalle_id',
        'cliente_privado_id',
        'cantidad_asignada',
    ];

    public function pedidoDetalle()
    {
        return $this->belongsTo(PedidoDetalle::class, 'pedido_detalle_id');
    }

    public function clientePrivado()
    {
        return $this->belongsTo(ClientePrivadoRevendedor::class, 'cliente_privado_id');
    }
}