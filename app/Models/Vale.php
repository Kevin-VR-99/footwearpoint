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
