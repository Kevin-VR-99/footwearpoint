<?php

namespace App\Services\Vale;

use App\Exceptions\OperacionInvalidaException;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\SolicitudCambio;
use App\Models\Vale;
use App\Models\ValeDevolucionPartida;
use App\Services\Auth\PermisoModuloService;
use App\Services\Pedido\RegistrarPagoPedidoAction;
use App\Support\Centavos;
use App\Support\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * TG-356 (G33 / S5-74) — Nota de devolución de producto, sin solicitud.
 *
 * Reglas aprobadas (plan v13 §470-472, §482; ronda 34):
 *  - Pedido ENTREGADO de la distribuidora actual; partidas y cantidades parciales.
 *  - Importe = precio pagado registrado en la partida × unidades devueltas
 *    (el pedido entregado está pagado completo; si no, el prorrateo sigue
 *    pendiente y se rechaza con ese motivo, sin inventarlo).
 *  - Tope acumulado por partida: nunca más unidades de las compradas.
 *  - Idempotente por clave; concurrencia con bloqueo del pedido y sus partidas.
 *  - Vigencia: «Días de vigencia del vale» de la distribuidora (90 por
 *    omisión, mínimo 30); a nombre del dueño del pedido. El sobrante que
 *    nace al canjearla dura siempre Vale::DIAS_VIGENCIA_TIPIFICADO.
 *
 * Fuera de alcance (pendiente, rechazo explícito): partidas no surtidas o
 * parciales, y partidas con una solicitud de cambio del flujo anterior.
 */
class EmitirNotaDevolucionAction
{
    public function __construct(private CrearValeTipificado $crear, private RegistrarPagoPedidoAction $pagos) {}

    /**
     * @param  array<int|string, int|string>  $cantidades  pedido_detalle_id => unidades a devolver
     */
    public function ejecutar(int $pedidoId, array $cantidades, string $clave): Vale
    {
        $distribuidoraId = Tenant::id();
        abort_if($distribuidoraId === null, 403, 'No se pudo determinar la distribuidora.');
        abort_unless(app(PermisoModuloService::class)->puede(auth()->user(), PermisoModuloService::CAMBIOS_RESOLVER), 403,
            'No tienes permiso para emitir notas de devolución.');
        $staffId = $this->crear->staffActual();
        abort_if($staffId === null, 403, 'Solo el personal activo de la distribuidora puede emitir notas.');

        $clave = trim($clave);
        if ($clave === '' || strlen($clave) > 64) {
            throw new OperacionInvalidaException('Falta la clave de la operación. Vuelve a abrir la devolución.', 422);
        }

        $pedir = [];
        foreach ($cantidades as $id => $cantidad) {
            if (! is_numeric($cantidad) || (string) (int) $cantidad !== trim((string) $cantidad) || (int) $cantidad < 0) {
                throw new OperacionInvalidaException('Las cantidades deben ser números enteros.', 422);
            }
            if ((int) $cantidad > 0) {
                $pedir[(int) $id] = (int) $cantidad;
            }
        }
        ksort($pedir);
        if ($pedir === []) {
            throw new OperacionInvalidaException('Elige al menos un producto y la cantidad a devolver.', 422);
        }

        try {
            return DB::transaction(fn () => $this->emitir($distribuidoraId, $staffId, $pedidoId, $pedir, $clave));
        } catch (UniqueConstraintViolationException) {
            // Dos envíos con la misma clave al mismo tiempo: el segundo devuelve la nota del primero.
            return $this->repetida($clave, $pedidoId, $pedir) ?? throw new OperacionInvalidaException('No se pudo emitir la nota. Intenta de nuevo.', 409);
        }
    }

    private function emitir(int $distribuidoraId, int $staffId, int $pedidoId, array $pedir, string $clave): Vale
    {
        $this->crear->bloquearNumeracion($distribuidoraId);
        $pedido = Pedido::query()->lockForUpdate()->find($pedidoId);
        if (! $pedido) {
            throw new OperacionInvalidaException('No se encontró un pedido con ese folio.', 404);
        }

        if ($repetida = $this->repetida($clave, $pedidoId, $pedir)) {
            return $repetida;
        }

        if ($pedido->estado !== 'entregado') {
            throw new OperacionInvalidaException('Solo se puede devolver producto de un pedido entregado.', 422);
        }
        if (Centavos::desdeDecimal(number_format($this->pagos->resumen($pedido)['saldo'], 2, '.', '')) > 0) {
            throw new OperacionInvalidaException('El pedido tiene saldo pendiente; el reparto de un pago incompleto aún no está definido.', 422);
        }

        $lineas = PedidoDetalle::query()->where('pedido_id', $pedido->id)->whereIn('id', array_keys($pedir))
            ->lockForUpdate()->get()->keyBy('id');
        if ($lineas->count() !== count($pedir)) {
            throw new OperacionInvalidaException('Alguna partida no pertenece a este pedido.', 422);
        }

        $devueltas = ValeDevolucionPartida::query()->whereIn('pedido_detalle_id', array_keys($pedir))
            ->groupBy('pedido_detalle_id')->selectRaw('pedido_detalle_id, SUM(cantidad) as total')
            ->pluck('total', 'pedido_detalle_id');
        $conSolicitud = SolicitudCambio::query()->whereIn('pedido_detalle_id', array_keys($pedir))
            ->where('estado', '!=', 'rechazada')->pluck('pedido_detalle_id')->all();

        $total = 0;
        $partidas = [];
        foreach ($pedir as $id => $cantidad) {
            $linea = $lineas[$id];
            if (in_array($linea->estado_surtido, ['no_surtido', 'parcial'], true)) {
                throw new OperacionInvalidaException('La partida '.$linea->producto_nombre.' no se surtió completa; su devolución aún no está definida.', 422);
            }
            if (in_array($id, $conSolicitud, false)) {
                throw new OperacionInvalidaException('La partida '.$linea->producto_nombre.' ya tiene una solicitud de cambio del flujo anterior.', 422);
            }
            $disponible = (int) $linea->cantidad - (int) ($devueltas[$id] ?? 0);
            if ($cantidad > $disponible) {
                throw new OperacionInvalidaException('De '.$linea->producto_nombre.' solo quedan '.max(0, $disponible).' unidades por devolver.', 422);
            }
            $precio = Centavos::desdeDecimal($linea->getRawOriginal('precio_unitario'));
            $monto = $precio * $cantidad;
            $total += $monto;
            $partidas[] = ['pedido_detalle_id' => $id, 'cantidad' => $cantidad, 'precio_unitario' => Centavos::aDecimal($precio), 'monto' => Centavos::aDecimal($monto)];
        }
        if ($total <= 0) {
            throw new OperacionInvalidaException('La nota debe ser mayor a cero.', 422);
        }

        $vale = $this->crear->crear(Vale::TIPO_DEVOLUCION, [
            'distribuidora_id' => $distribuidoraId,
            'cliente_directo_id' => $pedido->cliente_directo_id,
            'revendedor_distribuidora_id' => $pedido->revendedor_distribuidora_id,
        ], $total, $staffId, 'Devolución de producto del pedido '.$pedido->folio, pedidoOrigenId: $pedido->id, clave: $clave, pedidoMovimientoId: $pedido->id);

        foreach ($partidas as $p) {
            ValeDevolucionPartida::create($p + ['distribuidora_id' => $distribuidoraId, 'vale_id' => $vale->id]);
        }

        return $vale->fresh(['partidasDevolucion', 'clienteDirecto', 'revendedorAfiliacion.revendedor']);
    }

    /** Misma clave + mismo pedido + mismas cantidades = la misma nota. Otra cosa con la misma clave se rechaza. */
    private function repetida(string $clave, int $pedidoId, array $pedir): ?Vale
    {
        $vale = Vale::query()->with('partidasDevolucion')->where('clave_idempotencia', $clave)->first();
        if (! $vale) {
            return null;
        }
        $previas = $vale->partidasDevolucion->sortBy('pedido_detalle_id')->mapWithKeys(fn ($p) => [(int) $p->pedido_detalle_id => (int) $p->cantidad])->all();
        if ((int) $vale->pedido_origen_id !== $pedidoId || $previas !== $pedir) {
            throw new OperacionInvalidaException('Esta operación ya se usó para otra devolución. Vuelve a abrir la devolución.', 409);
        }

        return $vale->load(['clienteDirecto', 'revendedorAfiliacion.revendedor']);
    }
}
