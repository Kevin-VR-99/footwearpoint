<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HistorialEstadoPedido extends Model
{
    protected $table = 'historial_estados_pedido';

    // Esta tabla solo tiene created_at, no updated_at.
    // Es un historial inmutable: nunca se edita un registro ya creado.
    const UPDATED_AT = null;

    protected $fillable = [
        'distribuidora_id',
        'pedido_id',
        'estado_anterior',
        'estado_nuevo',
        'cambiado_por_staff_id',
        'comentario',
    ];

    public function pedido()
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }

    public function cambiadoPor()
    {
        return $this->belongsTo(DistribuidoraStaff::class, 'cambiado_por_staff_id');
    }
}