<?php

namespace App\Services\Pago;

use App\Models\Pago;
use App\Models\Pedido;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Services\MercadoPago\ClienteMercadoPago;
use App\Services\MercadoPago\MercadoPagoException;
use App\Services\Pedido\RegistrarPagoPedidoAction;
use App\Support\FolioPago;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * TG-226 (G7) — El cliente directo paga su anticipo con Checkout Pro.
 *
 * Crea un pago 'pendiente' (no cuenta como pagado: el resumen del pedido solo
 * suma los 'aplicado') y una preferencia de Checkout Pro con el token de la
 * distribuidora, así que el dinero llega directo a SU cuenta. El pago se
 * confirma después con VerificarPagoMercadoPagoAction (G7) o con el aviso de
 * Mercado Pago (G9); ambos usan AplicarPagoMercadoPagoAction.
 *
 * Reintentos:
 *   - si ya hay un enlace pendiente, vigente y por el mismo monto, se regresa
 *     el mismo (no se crea otra preferencia);
 *   - si el monto cambió o el enlace ya venció, el pago anterior pasa a
 *     'fallido', se vence su preferencia en Mercado Pago y se crea uno nuevo.
 *
 * La llamada a Mercado Pago va FUERA de la transacción para no tener
 * bloqueado el pedido mientras responde.
 */
class CrearPagoAnticipoMercadoPagoAction
{
    /** Minutos que se espera a otra petición que está creando la preferencia. */
    private const MINUTOS_EN_PREPARACION = 2;

    /** Un enlace que vence en menos de esto ya no se reutiliza. */
    private const MINUTOS_MARGEN_VIGENCIA = 10;

    public function __construct(
        private readonly ClienteMercadoPago $cliente,
        private readonly TokenMercadoPagoDistribuidora $tokens,
        private readonly RegistrarPagoPedidoAction $pagos,
        private readonly RegistrarAuditoriaAction $auditoria,
    ) {
    }

    /**
     * @return array{pago: Pago, init_point: string, reutilizado: bool, moneda: string}
     *
     * @throws MercadoPagoException con un mensaje listo para el cliente.
     */
    public function ejecutar(Pedido $pedido): array
    {
        $this->validarPedido($pedido);

        ['token' => $token, 'configuracion' => $configuracion] = $this->tokens->para((int) $pedido->distribuidora_id);

        $moneda = $configuracion->moneda ?: 'MXN';

        [$pago, $reutilizado, $aVencer] = DB::transaction(fn () => $this->reservarPago($pedido));

        if ($reutilizado) {
            $preferencia = $this->cliente->obtenerPreferencia($token, $pago->preferencia_externa);

            return ['pago' => $pago, 'init_point' => $preferencia['init_point'], 'reutilizado' => true, 'moneda' => $moneda];
        }

        // Los enlaces anteriores ya no deben aceptar pagos. Si Mercado Pago
        // no responde aquí no se detiene el cobro: ya quedó reportado y G9
        // aplica igual un pago que llegue a un enlace viejo.
        foreach ($aVencer as $preferenciaVieja) {
            try {
                $this->cliente->expirarPreferencia($token, $preferenciaVieja);
            } catch (MercadoPagoException) {
            }
        }

        try {
            $preferencia = $this->cliente->crearPreferencia(
                $token,
                $this->preferencia($pedido, $pago, $moneda)
            );
        } catch (MercadoPagoException $e) {
            $pago->update(['estado' => 'fallido']);

            throw $e;
        }

        $pago->update(['preferencia_externa' => $preferencia['id']]);

        $this->auditoria->ejecutar(
            'pago.mercado_pago.preferencia',
            'pago',
            $pago->id,
            null,
            [
                'pedido_id'    => $pedido->id,
                'folio'        => $pago->folio,
                'tipo'         => 'anticipo',
                'monto'        => (float) $pago->monto,
                'preferencia'  => $preferencia['id'],
                'reemplaza_a'  => $aVencer,
            ],
        );

        return ['pago' => $pago, 'init_point' => $preferencia['init_point'], 'reutilizado' => false, 'moneda' => $moneda];
    }

    private function validarPedido(Pedido $pedido): void
    {
        $mensaje = match (true) {
            $pedido->tipo !== 'cliente_directo' => MercadoPagoException::SOLO_CLIENTE_DIRECTO,
            $pedido->estado === 'borrador' => MercadoPagoException::PEDIDO_BORRADOR,
            in_array($pedido->estado, RegistrarPagoPedidoAction::ESTADOS_CERRADOS, true) => MercadoPagoException::PEDIDO_CERRADO,
            default => null,
        };

        if ($mensaje !== null) {
            throw MercadoPagoException::con($mensaje);
        }
    }

    /**
     * Con el pedido bloqueado: decide si se reutiliza el enlace pendiente o
     * se crea un pago nuevo.
     *
     * @return array{0: Pago, 1: bool, 2: list<string>}
     */
    private function reservarPago(Pedido $pedido): array
    {
        $pedido = Pedido::query()->whereKey($pedido->id)->lockForUpdate()->firstOrFail();
        $pedido->load(['detalle', 'pagos', 'aplicacionesVale']);

        $resumen = $this->pagos->resumen($pedido);
        $monto = round(min($resumen['anticipo_pendiente'], $resumen['saldo']), 2);

        if ($monto <= 0) {
            throw MercadoPagoException::con(MercadoPagoException::SIN_ANTICIPO_PENDIENTE);
        }

        $pendientes = $pedido->pagos
            ->filter(fn (Pago $p) => $p->tipo === 'anticipo' && $p->esMercadoPagoPendiente())
            ->sortByDesc('id');

        // Otra petición acaba de crear el pago y todavía espera a Mercado Pago.
        if ($pendientes->contains(fn (Pago $p) => $p->preferencia_externa === null
            && $p->created_at->gt(now()->subMinutes(self::MINUTOS_EN_PREPARACION)))) {
            throw MercadoPagoException::con(MercadoPagoException::PAGO_EN_PREPARACION);
        }

        $vigente = $pendientes->first(fn (Pago $p) => $p->preferencia_externa !== null
            && abs((float) $p->monto - $monto) < 0.009
            && $p->venceMercadoPagoAt()->gt(now()->addMinutes(self::MINUTOS_MARGEN_VIGENCIA)));

        if ($vigente !== null) {
            return [$vigente, true, []];
        }

        $aVencer = [];
        foreach ($pendientes as $anterior) {
            $anterior->update(['estado' => 'fallido']);

            if ($anterior->preferencia_externa !== null) {
                $aVencer[] = $anterior->preferencia_externa;
            }

            $this->auditoria->ejecutar(
                'pago.mercado_pago.reemplazado',
                'pago',
                $anterior->id,
                ['estado' => 'pendiente'],
                ['estado' => 'fallido', 'pedido_id' => $pedido->id],
            );
        }

        $pago = Pago::create([
            'distribuidora_id'        => $pedido->distribuidora_id,
            'pedido_id'               => $pedido->id,
            'venta_directa_id'        => null,
            'folio'                   => FolioPago::siguiente((int) $pedido->distribuidora_id),
            'tipo'                    => 'anticipo',
            'direccion'               => 'entrada',
            'metodo'                  => 'mercado_pago',
            'monto'                   => $monto,
            'fecha_pago'              => now(),
            'referencia'              => null,
            'proveedor_pago'          => 'mercado_pago',
            'referencia_externa'      => null,
            'preferencia_externa'     => null,
            'estado'                  => 'pendiente',
            // Lo hace el propio cliente desde la app (ver TG-138).
            'registrado_por_staff_id' => null,
        ]);

        return [$pago, false, $aVencer];
    }

    /** Cuerpo de POST /checkout/preferences. */
    private function preferencia(Pedido $pedido, Pago $pago, string $moneda): array
    {
        $preferencia = [
            'items' => [[
                'id'          => $pedido->folio,
                'title'       => "Anticipo del pedido {$pedido->folio}",
                'quantity'    => 1,
                'currency_id' => $moneda,
                'unit_price'  => (float) $pago->monto,
            ]],
            'external_reference' => $pago->referenciaMercadoPago(),
            'metadata' => [
                'pago_id'          => $pago->id,
                'pedido_id'        => $pedido->id,
                'distribuidora_id' => $pedido->distribuidora_id,
                'tipo'             => 'anticipo',
            ],
            // Solo aprobado o rechazado, sin pagos "en proceso", y sin
            // efectivo (OXXO) ni cajero, que tardan días en confirmarse.
            'binary_mode' => true,
            'payment_methods' => [
                'excluded_payment_types' => [['id' => 'ticket'], ['id' => 'atm']],
            ],
            'expires'              => true,
            'expiration_date_from' => $pago->created_at->format('Y-m-d\TH:i:s.vP'),
            'expiration_date_to'   => $pago->venceMercadoPagoAt()->format('Y-m-d\TH:i:s.vP'),
        ];

        // Mercado Pago descarta las URLs que no son https (y con auto_return
        // sin URL de éxito rechaza la preferencia). En local, sin https, el
        // cliente regresa con el botón de Mercado Pago y verifica en la app.
        $retorno = route('mercado-pago.retorno');
        if (str_starts_with($retorno, 'https://')) {
            $preferencia['back_urls'] = ['success' => $retorno, 'pending' => $retorno, 'failure' => $retorno];
            $preferencia['auto_return'] = 'approved';
        }

        // El aviso de Mercado Pago llega en G9: solo se manda cuando la ruta
        // ya existe.
        if (Route::has('mercado-pago.webhook')) {
            $aviso = route('mercado-pago.webhook');

            if (str_starts_with($aviso, 'https://')) {
                $preferencia['notification_url'] = $aviso;
            }
        }

        return $preferencia;
    }
}
