<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * TG-228 (G9) — Un aviso (webhook) que mandó Mercado Pago.
 *
 * Es una tabla del sistema, no de una distribuidora: cuando llega el aviso
 * todavía no se sabe de quién es (distribuidora_id se llena al procesarlo).
 * Por eso NO usa BelongsToTenant.
 *
 * ÚNICO (tipo, recurso_id): el mismo aviso repetido cae en la misma fila y
 * no se procesa dos veces. procesado_at solo se llena cuando el resultado es
 * definitivo; mientras siga nulo, el siguiente aviso del mismo recurso lo
 * vuelve a intentar.
 */
class WebhookMercadoPago extends Model
{
    protected $table = 'webhooks_mercado_pago';

    const UPDATED_AT = null;

    protected $fillable = [
        'tipo',
        'recurso_id',
        'distribuidora_id',
        'payload',
        'procesado_at',
        'error',
    ];

    protected $casts = [
        'payload'      => 'array',
        'procesado_at' => 'datetime',
    ];
}
