<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use App\Models\Concerns\BelongsToTenant;

class ConfiguracionDistribuidora extends Model
{
    use BelongsToTenant;
    protected $table = 'configuraciones_distribuidora';

    /**
     * TG-225 (G6): Mercado Pago da el token por 180 días. No se guarda el
     * refresh token (no hay columna), así que el vencimiento se calcula desde
     * mp_conectado_at y se avisa 15 días antes para volver a conectar.
     */
    public const DIAS_VIGENCIA_TOKEN_MP = 180;

    public const DIAS_AVISO_VENCIMIENTO_MP = 15;

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

    /**
     * TG-225 (G6): el token NUNCA sale en toArray()/JSON. Sin esto, cualquier
     * respuesta que serializara el modelo lo mostraría ya descifrado.
     */
    protected $hidden = [
        'mp_access_token',
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

    /**
     * TG-225 (G6) — El token de Mercado Pago ya descifrado, o null si no hay.
     *
     * Si no se puede descifrar (por ejemplo, si cambiara la APP_KEY) se
     * reporta y se trata como "sin conexión": la distribuidora solo tiene que
     * volver a conectar su cuenta.
     */
    public function mercadoPagoAccessToken(): ?string
    {
        if (blank($this->getRawOriginal('mp_access_token'))) {
            return null;
        }

        try {
            $token = $this->mp_access_token;
        } catch (DecryptException $e) {
            report($e);

            return null;
        }

        return filled($token) ? $token : null;
    }

    /** TG-225 (G6): true si tiene una conexión con Mercado Pago que se puede usar. */
    public function tieneMercadoPago(): bool
    {
        return $this->mp_conectado_at !== null && $this->mercadoPagoAccessToken() !== null;
    }

    /** TG-225 (G6): vencimiento aproximado del token (conexión + 180 días). */
    public function mercadoPagoVenceAprox(): ?Carbon
    {
        return $this->mp_conectado_at?->copy()->addDays(self::DIAS_VIGENCIA_TOKEN_MP);
    }
}