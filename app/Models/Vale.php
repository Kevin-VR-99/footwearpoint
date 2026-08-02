<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vale extends Model
{
    protected $table = 'vales';

    protected $fillable = [
        'distribuidora_id',
        'cliente_directo_id',
        'revendedor_distribuidora_id',
        'folio',
        'monto_original',
        'saldo_actual',
        'fecha_emision',
        'fecha_vencimiento',
        'estado',
        'motivo',
        'pedido_origen_id',
        'creado_por_staff_id',
    ];

    protected $casts = [
        'monto_original' => 'decimal:2',
        'saldo_actual' => 'decimal:2',
        'fecha_emision' => 'datetime',
        'fecha_vencimiento' => 'datetime',
    ];

    public function clienteDirecto()
    {
        return $this->belongsTo(ClienteDirecto::class, 'cliente_directo_id');
    }

    public function revendedorAfiliacion()
    {
        return $this->belongsTo(RevendedorDistribuidora::class, 'revendedor_distribuidora_id');
    }

    public function pedidoOrigen()
    {
        return $this->belongsTo(Pedido::class, 'pedido_origen_id');
    }

    public function creadoPor()
    {
        return $this->belongsTo(DistribuidoraStaff::class, 'creado_por_staff_id');
    }

    public function movimientos()
    {
        return $this->hasMany(ValeMovimiento::class, 'vale_id');
    }
}