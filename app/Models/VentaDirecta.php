<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VentaDirecta extends Model
{
    protected $table = 'ventas_directas';

    protected $fillable = [
        'distribuidora_id',
        'sucursal_id',
        'cliente_directo_id',
        'folio',
        'fecha_venta',
        'subtotal',
        'descuento',
        'total',
        'estado',
        'registrada_por_staff_id',
    ];

    protected $casts = [
        'fecha_venta' => 'datetime',
        'subtotal' => 'decimal:2',
        'descuento' => 'decimal:2',
        'total' => 'decimal:2',
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

    public function registradaPor()
    {
        return $this->belongsTo(DistribuidoraStaff::class, 'registrada_por_staff_id');
    }

    public function detalle()
    {
        return $this->hasMany(VentaDirectaDetalle::class, 'venta_directa_id');
    }

    public function pagos()
    {
        return $this->hasMany(Pago::class, 'venta_directa_id');
    }
}