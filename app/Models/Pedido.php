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

    /** Vales aplicados a este pedido. Cuentan como pago (TG-167). */
    public function aplicacionesVale()
    {
        return $this->hasMany(ValeMovimiento::class, 'pedido_id')->where('tipo', 'aplicacion');
    }

    /**
     * TG-125: cómo va el plazo de recolección de un pedido listo.
     *
     * 'vencida' si ya pasó la fecha límite (el comando diario lo pasará a
     * vencido_recoleccion), 'por_vencer' si faltan 48 horas o menos (igual
     * que la alerta del detalle, TG-244), null en cualquier otro caso.
     */
    public function alertaRecoleccion(): ?string
    {
        if ($this->estado !== 'listo_entrega' || ! $this->fecha_limite_recoleccion) {
            return null;
        }

        if (now()->greaterThan($this->fecha_limite_recoleccion)) {
            return 'vencida';
        }

        return now()->diffInHours($this->fecha_limite_recoleccion, false) <= 48 ? 'por_vencer' : null;
    }

    /** Reembolsos automáticos en Mercado Pago de esta pedido. */
    public function reembolsosMercadoPago()
    {
        return $this->hasMany(ReembolsoMercadoPago::class, 'pedido_id')->orderBy('id');
    }
}
