<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PedidoDetalle extends Model
{
    protected $table = 'pedido_detalle';

    protected $fillable = [
        'distribuidora_id',
        'pedido_id',
        'producto_campana_id',
        'variante_id',
        'producto_nombre',
        'modelo',
        'talla',
        'color',
        'cantidad',
        'precio_unitario',
        'subtotal',
        'anticipo_requerido',
        'estado_surtido',
        'cantidad_confirmada',
        'cantidad_recibida',
        'motivo_no_surtido',
        'resolucion_no_surtido',
    ];

    protected $casts = [
        'precio_unitario' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'anticipo_requerido' => 'decimal:2',
    ];

    public function pedido()
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }

    public function productoCampana()
    {
        return $this->belongsTo(ProductoCampana::class, 'producto_campana_id');
    }

    public function variante()
    {
        return $this->belongsTo(Variante::class, 'variante_id');
    }

    public function asignacionesClientePrivado()
    {
        return $this->hasMany(PedidoClientePrivado::class, 'pedido_detalle_id');
    }

    public function solicitudesCambio()
    {
        return $this->hasMany(SolicitudCambio::class, 'pedido_detalle_id');
    }
}