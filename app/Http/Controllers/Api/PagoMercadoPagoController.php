<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PedidoResource;
use App\Models\Pedido;
use App\Services\Pago\CrearPagoAnticipoMercadoPagoAction;
use App\Services\Pago\VerificarPagoMercadoPagoAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;

/**
 * TG-226 (G7) — El cliente directo paga su anticipo con Mercado Pago desde
 * la app (Checkout Pro). Solo sobre SUS pedidos: uno ajeno responde 404.
 *
 * Los errores (sin conexión con MP, sin anticipo pendiente, ...) son
 * MercadoPagoException y responden { "message": "..." } en español.
 */
class PagoMercadoPagoController extends Controller
{
    private const MENSAJES_VERIFICAR = [
        VerificarPagoMercadoPagoAction::APLICADO  => 'Recibimos tu anticipo. ¡Gracias!',
        VerificarPagoMercadoPagoAction::PENDIENTE => 'Tu pago todavía no se confirma. Revisa de nuevo en unos minutos.',
        VerificarPagoMercadoPagoAction::RECHAZADO => 'Mercado Pago rechazó el pago. Puedes intentarlo de nuevo con otro medio de pago.',
        VerificarPagoMercadoPagoAction::VENCIDO   => 'El enlace de pago venció. Genera uno nuevo para pagar tu anticipo.',
    ];

    public function crearAnticipo(int $id, CrearPagoAnticipoMercadoPagoAction $accion): JsonResponse
    {
        $pedido = $this->pedido($id);

        ['pago' => $pago, 'init_point' => $initPoint, 'reutilizado' => $reutilizado, 'moneda' => $moneda] = $accion->ejecutar($pedido);

        return response()->json([
            'data' => [
                'pago_id'        => $pago->id,
                'folio'          => $pago->folio,
                'monto'          => (float) $pago->monto,
                'moneda'         => $moneda,
                'preferencia_id' => $pago->preferencia_externa,
                'init_point'     => $initPoint,
                'vence_at'       => $pago->venceMercadoPagoAt()->toIso8601String(),
                'reutilizado'    => $reutilizado,
            ],
            'message' => 'Abre el enlace para pagar tu anticipo con Mercado Pago.',
        ], $reutilizado ? 200 : 201);
    }

    public function verificar(int $id, VerificarPagoMercadoPagoAction $accion): JsonResponse
    {
        $pedido = $this->pedido($id);

        $resultado = $accion->ejecutar($pedido);

        return response()->json([
            'data'      => new PedidoResource(
                Pedido::query()->with(['clienteDirecto', 'detalle', 'pagos'])->findOrFail($pedido->id)
            ),
            'resultado' => $resultado,
            'message'   => self::MENSAJES_VERIFICAR[$resultado],
        ]);
    }

    private function pedido(int $id): Pedido
    {
        abort_if(Tenant::id() === null, 403, 'No se pudo determinar la distribuidora.');

        return PropietarioActual::limitar(Pedido::query())->findOrFail($id);
    }
}
