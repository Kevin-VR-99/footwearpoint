<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pago\VerificarPagoMercadoPagoRequest;
use App\Http\Resources\PedidoResource;
use App\Models\Pago;
use App\Models\Pedido;
use App\Services\Pago\CrearPagoAnticipoMercadoPagoAction;
use App\Services\Pago\CrearPagoMayoristaMercadoPagoAction;
use App\Services\Pago\CrearPagoPedidoMercadoPagoAction;
use App\Services\Pago\CrearPagoSaldoMercadoPagoAction;
use App\Services\Pago\VerificarPagoMercadoPagoAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;

/**
 * TG-226 (G7) / TG-227 (G8) — El cliente directo paga su anticipo o el saldo
 * de su pedido con Mercado Pago desde la app (Checkout Pro). TG-229 (G10): el
 * cliente mayorista paga lo que falta de su pedido. Solo sobre SUS pedidos:
 * uno ajeno responde 404.
 *
 * Los errores (sin conexión con MP, sin anticipo o saldo pendiente, ...) son
 * MercadoPagoException y responden { "message": "..." } en español.
 */
class PagoMercadoPagoController extends Controller
{
    private const MENSAJES_VERIFICAR = [
        VerificarPagoMercadoPagoAction::APLICADO  => 'Recibimos tu anticipo. ¡Gracias!',
        VerificarPagoMercadoPagoAction::PENDIENTE => 'Tu pago todavía no se confirma. Revisa de nuevo en unos minutos.',
        VerificarPagoMercadoPagoAction::RECHAZADO => 'Mercado Pago rechazó el pago. Puedes intentarlo de nuevo con otro medio de pago.',
        VerificarPagoMercadoPagoAction::VENCIDO   => 'El enlace de pago venció. Genera uno nuevo para pagar tu anticipo.',
        VerificarPagoMercadoPagoAction::NO_CUADRA => 'Mercado Pago tiene un pago que no coincide con este anticipo, así que no se aplicó. No vuelvas a pagar: la distribuidora lo revisará contigo.',
    ];

    /** TG-227 (G8): los mismos casos, para el saldo. */
    private const MENSAJES_VERIFICAR_SALDO = [
        VerificarPagoMercadoPagoAction::APLICADO  => 'Recibimos el pago de tu saldo. ¡Gracias!',
        VerificarPagoMercadoPagoAction::PENDIENTE => 'Tu pago todavía no se confirma. Revisa de nuevo en unos minutos.',
        VerificarPagoMercadoPagoAction::RECHAZADO => 'Mercado Pago rechazó el pago. Puedes intentarlo de nuevo con otro medio de pago.',
        VerificarPagoMercadoPagoAction::VENCIDO   => 'El enlace de pago venció. Genera uno nuevo para pagar tu saldo.',
        VerificarPagoMercadoPagoAction::NO_CUADRA => 'Mercado Pago tiene un pago que no coincide con este saldo, así que no se aplicó. No vuelvas a pagar: la distribuidora lo revisará contigo.',
    ];

    /** TG-229 (G10): los mismos casos, para el pago del cliente mayorista. */
    private const MENSAJES_VERIFICAR_MAYORISTA = [
        VerificarPagoMercadoPagoAction::APLICADO  => 'Recibimos el pago de tu pedido. ¡Gracias!',
        VerificarPagoMercadoPagoAction::PENDIENTE => 'Tu pago todavía no se confirma. Revisa de nuevo en unos minutos.',
        VerificarPagoMercadoPagoAction::RECHAZADO => 'Mercado Pago rechazó el pago. Puedes intentarlo de nuevo con otro medio de pago.',
        VerificarPagoMercadoPagoAction::VENCIDO   => 'El enlace de pago venció. Genera uno nuevo para pagar tu pedido.',
        VerificarPagoMercadoPagoAction::NO_CUADRA => 'Mercado Pago tiene un pago que no coincide con este pedido, así que no se aplicó. No vuelvas a pagar: la distribuidora lo revisará contigo.',
    ];

    public function crearAnticipo(int $id, CrearPagoAnticipoMercadoPagoAction $accion): JsonResponse
    {
        return $this->respuestaEnlace(
            $accion->ejecutar($this->pedido($id)),
            'Abre el enlace para pagar tu anticipo con Mercado Pago.'
        );
    }

    /** TG-227 (G8): el saldo completo, cuando el pedido ya llegó a la distribuidora. */
    public function crearSaldo(int $id, CrearPagoSaldoMercadoPagoAction $accion): JsonResponse
    {
        return $this->respuestaEnlace(
            $accion->ejecutar($this->pedido($id)),
            'Abre el enlace para pagar el saldo de tu pedido con Mercado Pago.'
        );
    }

    /** TG-229 (G10): el cliente mayorista paga todo lo que falta de su pedido. */
    public function crearMayorista(int $id, CrearPagoMayoristaMercadoPagoAction $accion): JsonResponse
    {
        return $this->respuestaEnlace(
            $accion->ejecutar($this->pedido($id)),
            'Abre el enlace para pagar tu pedido con Mercado Pago.'
        );
    }

    /**
     * payment_id (opcional): el que Mercado Pago puso en la URL de regreso.
     * Con él se confirma directo con GET /v1/payments/{id}.
     */
    public function verificar(int $id, VerificarPagoMercadoPagoRequest $request, VerificarPagoMercadoPagoAction $accion): JsonResponse
    {
        return $this->verificarTipo($id, $request, $accion, CrearPagoPedidoMercadoPagoAction::ANTICIPO, self::MENSAJES_VERIFICAR);
    }

    /** TG-227 (G8): igual que verificar(), pero con los pagos del saldo. */
    public function verificarSaldo(int $id, VerificarPagoMercadoPagoRequest $request, VerificarPagoMercadoPagoAction $accion): JsonResponse
    {
        return $this->verificarTipo($id, $request, $accion, CrearPagoPedidoMercadoPagoAction::SALDO, self::MENSAJES_VERIFICAR_SALDO);
    }

    /** TG-229 (G10): igual que verificar(), con el pago del cliente mayorista. */
    public function verificarMayorista(int $id, VerificarPagoMercadoPagoRequest $request, VerificarPagoMercadoPagoAction $accion): JsonResponse
    {
        return $this->verificarTipo($id, $request, $accion, CrearPagoPedidoMercadoPagoAction::MAYORISTA, self::MENSAJES_VERIFICAR_MAYORISTA);
    }

    private function verificarTipo(
        int $id,
        VerificarPagoMercadoPagoRequest $request,
        VerificarPagoMercadoPagoAction $accion,
        string $tipo,
        array $mensajes,
    ): JsonResponse {
        $pedido = $this->pedido($id);

        $resultado = $accion->ejecutar($pedido, $request->pagoMpId(), 'api', $tipo);

        return response()->json([
            'data'      => new PedidoResource(
                Pedido::query()->with(['clienteDirecto', 'revendedorAfiliacion.revendedor', 'detalle', 'pagos'])->findOrFail($pedido->id)
            ),
            'resultado' => $resultado,
            'message'   => $mensajes[$resultado],
        ]);
    }

    /** @param  array{pago: Pago, init_point: string, reutilizado: bool, moneda: string}  $cobro */
    private function respuestaEnlace(array $cobro, string $mensaje): JsonResponse
    {
        ['pago' => $pago, 'init_point' => $initPoint, 'reutilizado' => $reutilizado, 'moneda' => $moneda] = $cobro;

        return response()->json([
            'data' => [
                'pago_id'        => $pago->id,
                'folio'          => $pago->folio,
                'tipo'           => $pago->tipo,
                'monto'          => (float) $pago->monto,
                'moneda'         => $moneda,
                'preferencia_id' => $pago->preferencia_externa,
                'init_point'     => $initPoint,
                'vence_at'       => $pago->venceMercadoPagoAt()->toIso8601String(),
                'reutilizado'    => $reutilizado,
            ],
            'message' => $mensaje,
        ], $reutilizado ? 200 : 201);
    }

    private function pedido(int $id): Pedido
    {
        abort_if(Tenant::id() === null, 403, 'No se pudo determinar la distribuidora.');

        return PropietarioActual::limitar(Pedido::query())->findOrFail($id);
    }
}
