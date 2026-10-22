<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class SolicitudCambio extends Model
{
    use BelongsToTenant;
    protected $table = 'solicitudes_cambio';

    protected $fillable = [
        'distribuidora_id',
        'pedido_detalle_id',
        'venta_detalle_id',
        'cliente_directo_id',
        'revendedor_distribuidora_id',
        'fecha_entrega_original',
        'fecha_solicitud',
        'dias_solicitud_aplicados',
        'fecha_limite_solicitud',
        'dias_gestion_fabrica_aplicados',
        'fecha_limite_gestion_fabrica',
        'estado',
        'vale_generado_id',
        'motivo',
        'resolucion',
    ];

    protected $casts = [
        'fecha_entrega_original' => 'datetime',
        'fecha_solicitud' => 'datetime',
        'fecha_limite_solicitud' => 'datetime',
        'fecha_limite_gestion_fabrica' => 'datetime',
    ];

    public function pedidoDetalle()
    {
        return $this->belongsTo(PedidoDetalle::class, 'pedido_detalle_id');
    }

    public function ventaDetalle()
    {
        return $this->belongsTo(VentaDirectaDetalle::class, 'venta_detalle_id');
    }

    public function clienteDirecto()
    {
        return $this->belongsTo(ClienteDirecto::class, 'cliente_directo_id');
    }

    public function revendedorAfiliacion()
    {
        return $this->belongsTo(RevendedorDistribuidora::class, 'revendedor_distribuidora_id');
    }

    /** Pasos de la solicitud, del más viejo al más nuevo. */
    public function historial()
    {
        return $this->hasMany(SolicitudCambioHistorial::class, 'solicitud_cambio_id')->orderBy('id');
    }

    public const ETIQUETAS = [
        'solicitada'       => 'Solicitada',
        'autorizada'       => 'Autorizada',
        'rechazada'        => 'Rechazada',
        'enviada_fabrica'  => 'Enviada a fábrica',
        'aceptada_fabrica' => 'Aceptada por fábrica',
        'vale_emitido'     => 'Vale emitido',
        'cerrada'          => 'Cerrada',
    ];

    /** estado actual => estados a los que puede pasar */
    public const TRANSICIONES = [
        'solicitada'       => ['autorizada', 'rechazada'],
        'autorizada'       => ['enviada_fabrica', 'vale_emitido', 'rechazada'],
        'enviada_fabrica'  => ['aceptada_fabrica', 'rechazada'],
        'aceptada_fabrica' => ['vale_emitido'],
        'vale_emitido'     => ['cerrada'],
        'rechazada'        => ['cerrada'],
        'cerrada'          => [],
    ];

    public static function etiqueta(string $estado): string
    {
        return self::ETIQUETAS[$estado] ?? ucfirst(str_replace('_', ' ', $estado));
    }

    /** @return list<string> */
    public function siguientesEstados(): array
    {
        return self::TRANSICIONES[$this->estado] ?? [];
    }

    protected static function booted(): void
    {
        // El primer paso de la línea de tiempo, por cualquier camino que se cree.
        static::created(function (SolicitudCambio $solicitud) {
            SolicitudCambioHistorial::create([
                'distribuidora_id'    => $solicitud->distribuidora_id,
                'solicitud_cambio_id' => $solicitud->id,
                'estado_anterior'     => null,
                'estado_nuevo'        => $solicitud->estado ?? 'solicitada',
                'nota'                => $solicitud->motivo,
            ]);
        });
    }

    public function valeGenerado()
    {
        return $this->belongsTo(Vale::class, 'vale_generado_id');
    }
}