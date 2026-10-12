<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Qué tan disponible está cada talla dentro de una temporada.
 *
 * Del catálogo compartido: la define la fábrica y es igual para todas las
 * distribuidoras (D2, TG-209).
 */
class DisponibilidadVarianteCampana extends Model
{
    protected $table = 'disponibilidad_variante_campana';

    // Esta tabla solo tiene updated_at, no created_at.
    const CREATED_AT = null;

    protected $fillable = [
        'producto_campana_id',
        'variante_id',
        'estado',
        'fecha_verificacion',
    ];

    protected $casts = [
        'fecha_verificacion' => 'datetime',
    ];

    /**
     * Estados con los que una talla se puede pedir (TG-214).
     *
     * El pedido se surte de fábrica por ciclo, no del mostrador: por eso
     * "bajo pedido" también se pide, solo que tarda más. Lo único que se
     * bloquea es lo que la fábrica ya no da.
     */
    public const ESTADOS_QUE_SE_PUEDEN_PEDIR = ['disponible', 'bajo_pedido'];

    public function sePuedePedir(): bool
    {
        return in_array($this->estado, self::ESTADOS_QUE_SE_PUEDEN_PEDIR, true);
    }

    /** Las tallas que se pueden pedir: para el catálogo y la captura de pedidos. */
    public function scopeSePuedenPedir($consulta)
    {
        return $consulta->whereIn('estado', self::ESTADOS_QUE_SE_PUEDEN_PEDIR);
    }

    /** Para la pantalla: "Bajo pedido" avisa que esa talla tarda más. */
    public function etiqueta(): string
    {
        return match ($this->estado) {
            'disponible' => 'Disponible',
            'bajo_pedido' => 'Bajo pedido',
            default => 'No disponible',
        };
    }

    /**
     * TG-266: qué líneas de pedido son "bajo pedido" HOY, en una sola consulta.
     *
     * Es el estado actual de la talla en esa temporada, no el que tenía al
     * pedirse (pedido_detalle no lo guarda). Devuelve [id de la línea => bool].
     *
     * @param  iterable<\App\Models\PedidoDetalle>  $lineas
     * @return array<int, bool>
     */
    public static function bajoPedidoPorLinea(iterable $lineas): array
    {
        $lineas = collect($lineas);
        $productos = $lineas->pluck('producto_campana_id')->filter()->unique()->values();

        $bajoPedido = $productos->isEmpty() ? collect() : static::query()
            ->whereIn('producto_campana_id', $productos)
            ->whereIn('variante_id', $lineas->pluck('variante_id')->filter()->unique()->values())
            ->where('estado', 'bajo_pedido')
            ->get(['producto_campana_id', 'variante_id'])
            ->map(fn ($d) => $d->producto_campana_id.':'.$d->variante_id)
            ->flip();

        return $lineas->mapWithKeys(fn ($l) => [
            (int) $l->id => $bajoPedido->has($l->producto_campana_id.':'.$l->variante_id),
        ])->all();
    }

    public function productoCampana()
    {
        return $this->belongsTo(ProductoCampana::class, 'producto_campana_id');
    }

    public function variante()
    {
        return $this->belongsTo(Variante::class, 'variante_id');
    }
}