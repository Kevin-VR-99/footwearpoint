<?php

namespace App\Services\Pedido;

use App\Models\Pedido;
use App\Services\CambiarEstadoPedidoService;
use App\Support\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * TG-125 (E13-02) — Los pedidos listos que nadie recogió a tiempo.
 *
 * Lo corre una vez al día el comando pedidos:vencer-recoleccion.
 *
 *  - Solo pedidos en «listo_entrega» con fecha_limite_recoleccion ya pasada.
 *    Esa fecha se pone al marcarlo listo (días máximos de recolección de la
 *    distribuidora, TG-51).
 *
 *  - El cambio va por CambiarEstadoPedidoService, el único lugar que cambia
 *    pedidos.estado: así queda el historial, la auditoría y el aviso al
 *    personal de la distribuidora, igual que un cambio hecho a mano.
 *
 *  - Es seguro correrlo varias veces: un pedido ya vencido deja de estar
 *    «listo_entrega» y no se vuelve a tocar. Antes de cambiarlo se vuelve a
 *    leer con candado, por si alguien lo entregó mientras tanto.
 *
 *  - Mira todas las distribuidoras (no corre dentro de ninguna); cada pedido
 *    se procesa dentro de la suya, así el historial, la auditoría y los
 *    avisos quedan con la distribuidora correcta.
 *
 *  - Qué hacer con un pedido vencido (sustitución o reembolso del anticipo)
 *    lo decide la distribuidora después; aquí no se toca.
 */
class VencerRecoleccionAction
{
    public const COMENTARIO = 'Venció el plazo de recolección';

    public function __construct(private CambiarEstadoPedidoService $cambiarEstado)
    {
    }

    /** Los pedidos que hoy ya se pasaron de su plazo. */
    public function pendientes(): Collection
    {
        return Pedido::withoutGlobalScopes()
            ->where('estado', 'listo_entrega')
            ->whereNotNull('fecha_limite_recoleccion')
            ->where('fecha_limite_recoleccion', '<', now())
            ->orderBy('fecha_limite_recoleccion')
            ->get();
    }

    /**
     * @return array{revisados: int, vencidos: int, folios: list<string>}
     */
    public function ejecutar(bool $simular = false): array
    {
        $pedidos = $this->pendientes();
        $folios = [];

        foreach ($pedidos as $pedido) {
            if ($simular) {
                $folios[] = $pedido->folio;

                continue;
            }

            try {
                $vencido = Tenant::forzar((int) $pedido->distribuidora_id, fn () => DB::transaction(function () use ($pedido) {
                    $actual = Pedido::withoutGlobalScopes()->lockForUpdate()->find($pedido->id);

                    // Alguien lo entregó (o lo cambió) mientras tanto.
                    if (! $actual || $actual->estado !== 'listo_entrega'
                        || ! $actual->fecha_limite_recoleccion || $actual->fecha_limite_recoleccion->isFuture()) {
                        return false;
                    }

                    $this->cambiarEstado->cambiar($actual, 'vencido_recoleccion', null, self::COMENTARIO);

                    return true;
                }));

                if ($vencido) {
                    $folios[] = $pedido->folio;
                }
            } catch (Throwable $e) {
                // Un pedido que falla no detiene a los demás; mañana se reintenta.
                Log::error('No se pudo vencer la recolección de un pedido.', [
                    'pedido_id' => $pedido->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return [
            'revisados' => $pedidos->count(),
            'vencidos'  => $simular ? 0 : count($folios),
            'folios'    => $folios,
        ];
    }
}
