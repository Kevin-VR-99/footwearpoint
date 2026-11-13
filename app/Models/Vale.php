<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class Vale extends Model
{
    use BelongsToTenant;
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
        'tipo',
        'vale_origen_id',
        'clave_idempotencia',
        // Auditoría del clic. Sin FK a propósito (sobrevive al borrado de la cuenta) y no sale en ValeResource.
        'creado_por_usuario_id',
    ];

    /** TG-356: tipos de vale. NULL = vale anterior (legacy), con sus reglas de siempre. */
    public const TIPO_ANTICIPO = 'anticipo';
    public const TIPO_DEVOLUCION = 'devolucion_producto';
    public const TIPO_BONO = 'bono';
    public const TIPO_SOBRANTE = 'sobrante';

    /** TG-356 (ronda 34): notas y vales tipificados vencen 90 días después de su emisión. */
    public const DIAS_VIGENCIA_TIPIFICADO = 90;

    /** Mínimo de «Días de vigencia del vale» (vales y notas de devolución), para que nadie emita vales que venzan casi de inmediato. */
    public const DIAS_VIGENCIA_MINIMA = 30;

    public static function etiquetaTipo(?string $tipo): string
    {
        return match ($tipo) {
            self::TIPO_ANTICIPO => 'Anticipo',
            self::TIPO_DEVOLUCION => 'Devolución de producto',
            self::TIPO_BONO => 'Bono',
            self::TIPO_SOBRANTE => 'Sobrante',
            default => 'Vale',
        };
    }

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

    public function valeOrigen()
    {
        return $this->belongsTo(Vale::class, 'vale_origen_id');
    }

    public function partidasDevolucion()
    {
        return $this->hasMany(ValeDevolucionPartida::class, 'vale_id');
    }

    public function creadoPor()
    {
        return $this->belongsTo(DistribuidoraStaff::class, 'creado_por_staff_id');
    }

    public function movimientos()
    {
        return $this->hasMany(ValeMovimiento::class, 'vale_id');
    }

    /**
     * TG-122: días de anticipación del aviso de vencimiento
     * (VALES_DIAS_AVISO_VENCIMIENTO, el mismo margen del aviso de TG-242).
     */
    public static function diasAvisoVencimiento(): int
    {
        return max(0, (int) config('vales.dias_aviso_vencimiento'));
    }

    /**
     * TG-122: vales que vencen pronto y todavía sirven: activos, con saldo,
     * aún no vencidos y con vencimiento dentro del margen.
     */
    public function scopePorVencer($consulta, ?int $dias = null)
    {
        $dias = $dias ?? static::diasAvisoVencimiento();

        return $consulta
            ->where('estado', 'activo')
            ->where('saldo_actual', '>', 0)
            ->whereNotNull('fecha_vencimiento')
            ->where('fecha_vencimiento', '>=', now())
            ->where('fecha_vencimiento', '<=', now()->addDays(max(0, $dias))->endOfDay());
    }

    /** TG-122: lo mismo que [scopePorVencer], para un vale ya cargado. */
    public function estaPorVencer(?int $dias = null): bool
    {
        $dias = $dias ?? static::diasAvisoVencimiento();

        return $this->estado === 'activo'
            && (float) $this->saldo_actual > 0
            && $this->fecha_vencimiento !== null
            && $this->fecha_vencimiento->greaterThanOrEqualTo(now())
            && $this->fecha_vencimiento->lessThanOrEqualTo(now()->addDays(max(0, $dias))->endOfDay());
    }
}
