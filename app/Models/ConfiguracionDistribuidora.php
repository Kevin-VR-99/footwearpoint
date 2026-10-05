<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class ConfiguracionDistribuidora extends Model
{
    use BelongsToTenant;
    protected $table = 'configuraciones_distribuidora';

    protected $fillable = [
        'distribuidora_id',
        'anticipo_por_producto',
        'dias_solicitud_cambio',
        'dias_gestion_devolucion',
        'dias_vigencia_vale',
        'dias_maximos_recoleccion',
        'moneda',
        'zona_horaria',
        'descuento_mayorista_pct',
        'mercado_pago_account_id',
        'mp_access_token',
        'mp_public_key',
        'mp_conectado_at',
    ];

    protected $casts = [
        'anticipo_por_producto' => 'decimal:2',
        'descuento_mayorista_pct' => 'decimal:2',
        // El token de Mercado Pago cobra dinero: se guarda cifrado en la base
        // y solo se ve descifrado desde el código (TG-208).
        'mp_access_token' => 'encrypted',
        'mp_conectado_at' => 'datetime',
    ];

    public function distribuidora()
    {
        return $this->belongsTo(Distribuidora::class, 'distribuidora_id');
    }
}