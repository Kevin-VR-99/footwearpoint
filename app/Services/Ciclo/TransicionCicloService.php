<?php

namespace App\Services\Ciclo;

use App\Exceptions\OperacionInvalidaException;
use App\Models\CicloCompra;
use App\Models\ConfiguracionCiclo;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Services\CambiarEstadoPedidoService;
use App\Support\ContextoOperativo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * E10 - Las 5 transiciones del ciclo de compra.
 *
 * abierto -> cerrado -> solicitado -> en_transito -> recibido -> finalizado
 *
 * Se separaron en 5 endpoints porque dia_cierre y dia_solicitud_fabrica son
 * configuraciones distintas en el modelo: juntarlas dejaria mal servida a
 * cualquier distribuidora que las use en dias distintos.
 *
 * NUNCA se escribe $pedido->estado = ... aqui. Todo cambio de estado de
 * pedido pasa por CambiarEstadoPedidoService (Fase 0), que ademas inserta
 * el historial_estados_pedido.
 */
class TransicionCicloService
{
    /** Pedidos que si se mandan a fabrica al solicitar el ciclo. */
    private const PEDIDOS_A_SOLICITAR = ['confirmado', 'incluido_en_ciclo'];

    /** Pedidos que si se marcan como recibidos al llegar el ciclo. */
    private const PEDIDOS_A_RECIBIR = ['solicitado_fabrica', 'en_transito'];

    /** Estados terminales: un pedido en cualquiera de estos ya esta resuelto. */
    private const PEDIDOS_RESUELTOS = [
        'entregado',
        'no_surtido',
        'vencido_recoleccion',
        'descartado',
        'rechazado',
    ];

    public function __construct(private ContextoOperativo $contexto)
    {
    }

    public function ver(int $cicloId): ResultadoCiclo
    {
        return $this->resultado($this->buscarCiclo($cicloId, bloquear: false));
    }

    /** abierto -> cerrado. No toca pedidos: solo deja de aceptar nuevos. */
    public function cerrar(int $cicloId): ResultadoCiclo
    {
        return DB::transaction(function () use ($cicloId) {
            $ciclo = $this->buscarCiclo($cicloId);
            $this->exigirEstado($ciclo, 'abierto');

            $ciclo->forceFill(['estado' => 'cerrado'])->save();

            return $this->resultado($ciclo);
        });
    }

    /** cerrado -> solicitado. Pasa los pedidos a 'solicitado_fabrica'. */
    public function solicitarFabrica(int $cicloId): ResultadoCiclo
    {
        return DB::transaction(function () use ($cicloId) {
            $ciclo = $this->buscarCiclo($cicloId);
            $this->exigirEstado($ciclo, 'cerrado');

            $ahora = Carbon::now();

            $ciclo->forceFill([
                'estado' => 'solicitado',
                'fecha_solicitud_fabrica' => $ahora,
                // Ahora si es la fecha real, no la proyeccion de la creacion.
                'fecha_estimada_llegada' => $ahora->copy()
                    ->addDays($this->diasEstimadosLlegada($ciclo))
                    ->toDateString(),
            ])->save();

            $this->cambiarEstadoDePedidos(
                $this->pedidosDelCiclo($ciclo, self::PEDIDOS_A_SOLICITAR),
                'solicitado_fabrica'
            );

            return $this->resultado($ciclo);
        });
    }

    /** solicitado -> en_transito. No toca pedidos. */
    public function marcarTransito(int $cicloId): ResultadoCiclo
    {
        return DB::transaction(function () use ($cicloId) {
            $ciclo = $this->buscarCiclo($cicloId);
            $this->exigirEstado($ciclo, 'solicitado');

            $ciclo->forceFill(['estado' => 'en_transito'])->save();

            return $this->resultado($ciclo);
        });
    }

    /**
     * en_transito -> recibido. Pasa los pedidos a 'recibido_distribuidora'.
     * Es el paso que el Paquete D espera antes de calcular el saldo de
     * llegada: su endpoint recibe el pedido ya en ese estado.
     */
    public function marcarRecibido(int $cicloId): ResultadoCiclo
    {
        return DB::transaction(function () use ($cicloId) {
            $ciclo = $this->buscarCiclo($cicloId);
            $this->exigirEstado($ciclo, 'en_transito');

            $ciclo->forceFill([
                'estado' => 'recibido',
                'fecha_recepcion' => Carbon::now(),
            ])->save();

            $this->cambiarEstadoDePedidos(
                $this->pedidosDelCiclo($ciclo, self::PEDIDOS_A_RECIBIR),
                'recibido_distribuidora'
            );

            return $this->resultado($ciclo);
        });
    }

    /** recibido -> finalizado. Exige que no queden pedidos sin resolver. */
    public function finalizar(int $cicloId): ResultadoCiclo
    {
        return DB::transaction(function () use ($cicloId) {
            $ciclo = $this->buscarCiclo($cicloId);
            $this->exigirEstado($ciclo, 'recibido');

            $sinResolver = $this->pedidosDelCiclo($ciclo)
                ->reject(fn ($pedido) => in_array($pedido->estado, self::PEDIDOS_RESUELTOS, true));

            if ($sinResolver->isNotEmpty()) {
                throw new OperacionInvalidaException(
                    'El ciclo todavia tiene pedidos sin resolver.',
                    409,
                    ['pedidos' => $sinResolver
                        ->map(fn ($p) => "Pedido {$p->folio}: {$p->estado}")
                        ->values()
                        ->all()]
                );
            }

            $ciclo->forceFill(['estado' => 'finalizado'])->save();

            return $this->resultado($ciclo);
        });
    }

    /**
     * UNICO punto del paquete que toca pedidos.estado. Si la firma de
     * CambiarEstadoPedidoService cambia, se ajusta solo aqui.
     */
    private function cambiarEstadoDePedidos(Collection $pedidos, string $nuevoEstado): void
    {
        if ($pedidos->isEmpty()) {
            return;
        }

        app(CambiarEstadoPedidoService::class)->cambiarParaCiclo($pedidos, $nuevoEstado);
    }

    /**
     * @param array<int, string>|null $estados
     * @return Collection<int, Pedido>
     */
    private function pedidosDelCiclo(CicloCompra $ciclo, ?array $estados = null): Collection
    {
        $query = Pedido::query()
            ->where('distribuidora_id', $ciclo->distribuidora_id)
            ->where('ciclo_compra_id', $ciclo->id);

        if ($estados !== null) {
            $query->whereIn('estado', $estados);
        }

        return $query->orderBy('id')->get();
    }

    /**
     * Resumen por variante y cantidad, para la orden a fabrica. Se agrupa en
     * PHP en vez de con SQL crudo (convencion E17-02). Los textos salen de la
     * fotografia historica de pedido_detalle, no del catalogo actual.
     *
     * @return array<int, array<string, mixed>>
     */
    private function consolidado(CicloCompra $ciclo): array
    {
        $pedidoIds = $this->pedidosDelCiclo($ciclo)->pluck('id');

        if ($pedidoIds->isEmpty()) {
            return [];
        }

        return PedidoDetalle::query()
            ->where('distribuidora_id', $ciclo->distribuidora_id)
            ->whereIn('pedido_id', $pedidoIds)
            ->orderBy('variante_id')
            ->get()
            ->groupBy('variante_id')
            ->map(function (Collection $lineas, $varianteId) {
                $primera = $lineas->first();

                return [
                    'variante_id' => (int) $varianteId,
                    'producto_nombre' => $primera->producto_nombre,
                    'modelo' => $primera->modelo,
                    'talla' => $primera->talla,
                    'color' => $primera->color,
                    'cantidad_total' => (int) $lineas->sum('cantidad'),
                ];
            })
            ->values()
            ->all();
    }

    private function resultado(CicloCompra $ciclo): ResultadoCiclo
    {
        return new ResultadoCiclo(
            $ciclo,
            $this->pedidosDelCiclo($ciclo),
            $this->consolidado($ciclo)
        );
    }

    private function buscarCiclo(int $cicloId, bool $bloquear = true): CicloCompra
    {
        $query = CicloCompra::query()
            ->where('distribuidora_id', $this->contexto->distribuidoraId())
            ->whereKey($cicloId);

        if ($bloquear) {
            $query->lockForUpdate();
        }

        $ciclo = $query->first();

        if ($ciclo === null) {
            throw new OperacionInvalidaException('El ciclo de compra no existe.', 404);
        }

        return $ciclo;
    }

    private function exigirEstado(CicloCompra $ciclo, string $esperado): void
    {
        if ($ciclo->estado !== $esperado) {
            throw new OperacionInvalidaException(
                "Esta accion solo se permite sobre un ciclo en estado '{$esperado}'. "
                . "El ciclo esta en '{$ciclo->estado}'.",
                409
            );
        }
    }

    private function diasEstimadosLlegada(CicloCompra $ciclo): int
    {
        if ($ciclo->configuracion_ciclo_id === null) {
            return 0;
        }

        $config = ConfiguracionCiclo::query()
            ->where('distribuidora_id', $ciclo->distribuidora_id)
            ->whereKey($ciclo->configuracion_ciclo_id)
            ->first();

        return $config !== null ? (int) $config->dias_estimados_llegada : 0;
    }
}