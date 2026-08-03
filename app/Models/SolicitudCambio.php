<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class SolicitudCambio extends Model
{
    use BelongsToTenant;
    protected $table = 'solicitudes_cambio';

    protected $fillable = [
        'distribuidora_id',
        'pedido_detalle_id',
        'venta_detalle_id',
        'cliente_directo_id',
        'revendedor_distribuidora_id',
        'fecha_entrega_original',
        'fecha_solicitud',
        'dias_solicitud_aplicados',
        'fecha_limite_solicitud',
        'dias_gestion_fabrica_aplicados',
        'fecha_limite_gestion_fabrica',
        'estado',
        'vale_generado_id',
        'motivo',
        'resolucion',
    ];

    protected $casts = [
        'fecha_entrega_original' => 'datetime',
        'fecha_solicitud' => 'datetime',
        'fecha_limite_solicitud' => 'datetime',
        'fecha_limite_gestion_fabrica' => 'datetime',
    ];

    public function pedidoDetalle()
    {
        return $this->belongsTo(PedidoDetalle::class, 'pedido_detalle_id');
    }

    public function ventaDetalle()
    {
        return $this->belongsTo(VentaDirectaDetalle::class, 'venta_detalle_id');
    }

    public function clienteDirecto()
    {
        return $this->belongsTo(ClienteDirecto::class, 'cliente_directo_id');
    }

    public function revendedorAfiliacion()
    {
        return $this->belongsTo(RevendedorDistribuidora::class, 'revendedor_distribuidora_id');
    }

    public function valeGenerado()
    {
        return $this->belongsTo(Vale::class, 'vale_generado_id');
    }
}