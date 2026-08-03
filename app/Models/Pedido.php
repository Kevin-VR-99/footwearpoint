<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class Pedido extends Model
{
    use BelongsToTenant;
    protected $table = 'pedidos';

    protected $fillable = [
        'distribuidora_id',
        'sucursal_id',
        'folio',
        'tipo',
        'cliente_directo_id',
        'revendedor_distribuidora_id',
        'ciclo_compra_id',
        'estado',
        'subtotal',
        'total',
        'fecha_colocacion',
        'fecha_estimada_llegada',
        'fecha_listo_entrega',
        'fecha_limite_recoleccion',
        'fecha_entrega',
        'resolucion_recoleccion',
        'capturado_por_staff_id',
        'observaciones',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'total' => 'decimal:2',
        'fecha_colocacion' => 'datetime',
        'fecha_estimada_llegada' => 'date',
        'fecha_listo_entrega' => 'datetime',
        'fecha_limite_recoleccion' => 'datetime',
        'fecha_entrega' => 'datetime',
    ];

    public function distribuidora()
    {
        return $this->belongsTo(Distribuidora::class, 'distribuidora_id');
    }

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }

    public function clienteDirecto()
    {
        return $this->belongsTo(ClienteDirecto::class, 'cliente_directo_id');
    }

    public function revendedorAfiliacion()
    {
        return $this->belongsTo(RevendedorDistribuidora::class, 'revendedor_distribuidora_id');
    }

    public function ciclo()
    {
        return $this->belongsTo(CicloCompra::class, 'ciclo_compra_id');
    }

    public function capturadoPor()
    {
        return $this->belongsTo(DistribuidoraStaff::class, 'capturado_por_staff_id');
    }

    public function detalle()
    {
        return $this->hasMany(PedidoDetalle::class, 'pedido_id');
    }

    public function historialEstados()
    {
        return $this->hasMany(HistorialEstadoPedido::class, 'pedido_id');
    }

    public function pagos()
    {
        return $this->hasMany(Pago::class, 'pedido_id');
    }

    public function valesOrigen()
    {
        return $this->hasMany(Vale::class, 'pedido_origen_id');
    }
}