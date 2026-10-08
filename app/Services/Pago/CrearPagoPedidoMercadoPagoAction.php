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
use InvalidArgumentException;

/**
 * TG-226 (G7) / TG-227 (G8) — El cliente directo paga su anticipo o el saldo
 * de su pedido con Checkout Pro.
 *
 * Crea un pago 'pendiente' (no cuenta como pagado: el resumen del pedido solo
 * suma los 'aplicado') y una preferencia de Checkout Pro con el token de la
 * distribuidora, así que el dinero llega directo a SU cuenta. El pago se
 * confirma después con VerificarPagoMercadoPagoAction (G7) o con el aviso de
 * Mercado Pago (G9); ambos usan AplicarPagoMercadoPagoAction.
 *
 * Qué se cobra:
 *   - anticipo: lo que falta del anticipo (nunca más que el saldo);
 *   - saldo: TODO el saldo, sin pagos parciales, solo cuando el anticipo ya
 *     está cubierto y el pedido ya llegó a la distribuidora (el total ya no
 *     cambia y así no hay que devolver dinero si algo no se surte).
 *
 * Reintentos (un solo enlace vivo por pedido):
 *   - si ya hay un enlace pendiente, vigente, del mismo tipo y por el mismo
 *     monto, se regresa el mismo (no se crea otra preferencia);
 *   - cualquier otro pago pendiente con Mercado Pago del pedido (otro monto,
 *     vencido o del otro tipo) pasa a 'fallido', se vence su preferencia en
 *     Mercado Pago y se crea uno nuevo. Si alguien alcanzó a pagar un enlace
 *     viejo, AplicarPagoMercadoPagoAction lo aplica igual y lo marca en la
 *     auditoría.
 *
 * El mostrador no se bloquea: puede seguir cobrando a mano.
 *
 * La llamada a Mercado Pago va FUERA de la transacción para no tener
 * bloqueado el pedido mientras responde.
 */
class CrearPagoPedidoMercadoPagoAction
{
    public const ANTICIPO = 'anticipo';

    public const SALDO = 'saldo_pedido';

    /** TG-227: el saldo se paga cuando la mercancía ya está en la distribuidora. */
    public const ESTADOS_PARA_SALDO = ['recibido_distribuidora', 'listo_entrega'];

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
     * ¿El dueño puede pagar ya el saldo de este pedido con Mercado Pago? Lo
     * usa PedidoResource para que la app sepa si mostrar el botón. No revisa
     * la conexión con Mercado Pago (eso lo dice el error al intentarlo).
     *
     * @param  array  $resumen  RegistrarPagoPedidoAction::resumen() del pedido.
     */
    public static function puedePagarSaldo(Pedido $pedido, array $resumen): bool
    {
        return self::motivoParaNoCobrarSaldo($pedido, $resumen) === null;
    }

    /**
     * @return array{pago: Pago, init_point: string, reutilizado: bool, moneda: string}
     *
     * @throws MercadoPagoException con un mensaje listo para el cliente.
     */
    public function ejecutar(Pedido $pedido, string $tipo): array
    {
        if (! in_array($tipo, [self::ANTICIPO, self::SALDO], true)) {
            throw new InvalidArgumentException("Tipo de pago con Mercado Pago no soportado: {$tipo}");
        }

        $this->validarPedido($pedido, $tipo);

        ['token' => $token, 'configuracion' => $configuracion] = $this->tokens->para((int) $pedido->distribuidora_id);

        $moneda = $configuracion->moneda ?: 'MXN';

        [$pago, $reutilizado, $aVencer] = DB::transaction(fn () => $this->reservarPago($pedido, $tipo));

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
                $this->preferencia($pedido, $pago, $moneda, $tipo)
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
                'tipo'         => $tipo,
                'monto'        => (float) $pago->monto,
                'preferencia'  => $preferencia['id'],
                'reemplaza_a'  => $aVencer,
            ],
        );

        return ['pago' => $pago, 'init_point' => $preferencia['init_point'], 'reutilizado' => false, 'moneda' => $moneda];
    }

    private function validarPedido(Pedido $pedido, string $tipo): void
    {
        $mensaje = $tipo === self::ANTICIPO
            ? match (true) {
                $pedido->tipo !== 'cliente_directo' => MercadoPagoException::SOLO_CLIENTE_DIRECTO,
                $pedido->estado === 'borrador' => MercadoPagoException::PEDIDO_BORRADOR,
                in_array($pedido->estado, RegistrarPagoPedidoAction::ESTADOS_CERRADOS, true) => MercadoPagoException::PEDIDO_CERRADO,
                default => null,
            }
            : self::motivoParaNoCobrarSaldo($pedido, $this->pagos->resumen($pedido));

        if ($mensaje !== null) {
            throw MercadoPagoException::con($mensaje);
        }
    }

    /** Por qué todavía no se puede cobrar el saldo con Mercado Pago, o null si sí. */
    private static function motivoParaNoCobrarSaldo(Pedido $pedido, array $resumen): ?string
    {
        return match (true) {
            $pedido->tipo !== 'cliente_directo' => MercadoPagoException::SOLO_CLIENTE_DIRECTO,
            $pedido->estado === 'borrador' => MercadoPagoException::SALDO_TODAVIA_NO,
            in_array($pedido->estado, RegistrarPagoPedidoAction::ESTADOS_CERRADOS, true) => MercadoPagoException::PEDIDO_CERRADO,
            $resumen['anticipo_pendiente'] > 0 => MercadoPagoException::SALDO_ANTES_DE_ANTICIPO,
            $resumen['saldo'] <= 0 => MercadoPagoException::SIN_SALDO_PENDIENTE,
            ! in_array($pedido->estado, self::ESTADOS_PARA_SALDO, true) => MercadoPagoException::SALDO_TODAVIA_NO,
            default => null,
        };
    }

    /**
     * Con el pedido bloqueado: decide si se reutiliza el enlace pendiente o
     * se crea un pago nuevo.
     *
     * @return array{0: Pago, 1: bool, 2: list<string>}
     */
    private function reservarPago(Pedido $pedido, string $tipo): array
    {
        $pedido = Pedido::query()->whereKey($pedido->id)->lockForUpdate()->firstOrFail();
        $pedido->load(['detalle', 'pagos', 'aplicacionesVale']);

        $resumen = $this->pagos->resumen($pedido);

        if ($tipo === self::ANTICIPO) {
            $monto = round(min($resumen['anticipo_pendiente'], $resumen['saldo']), 2);

            if ($monto <= 0) {
                throw MercadoPagoException::con(MercadoPagoException::SIN_ANTICIPO_PENDIENTE);
            }
        } else {
            // Se vuelve a revisar con el pedido bloqueado: mientras tanto
            // pudo cambiar (un pago en mostrador, un vale, otro estado).
            $motivo = self::motivoParaNoCobrarSaldo($pedido, $resumen);

            if ($motivo !== null) {
                throw MercadoPagoException::con($motivo);
            }

            $monto = round($resumen['saldo'], 2);
        }

        // Todos los pagos con Mercado Pago pendientes del pedido, de cualquier
        // tipo: solo debe quedar un enlace vivo.
        $pendientes = $pedido->pagos
            ->filter(fn (Pago $p) => $p->esMercadoPagoPendiente())
            ->sortByDesc('id');

        // Otra petición acaba de crear el pago y todavía espera a Mercado Pago.
        if ($pendientes->contains(fn (Pago $p) => $p->preferencia_externa === null
            && $p->created_at->gt(now()->subMinutes(self::MINUTOS_EN_PREPARACION)))) {
            throw MercadoPagoException::con(MercadoPagoException::PAGO_EN_PREPARACION);
        }

        $vigente = $pendientes->first(fn (Pago $p) => $p->tipo === $tipo
            && $p->preferencia_externa !== null
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
            'tipo'                    => $tipo,
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
    private function preferencia(Pedido $pedido, Pago $pago, string $moneda, string $tipo): array
    {
        $concepto = $tipo === self::ANTICIPO ? 'Anticipo' : 'Saldo';

        $preferencia = [
            'items' => [[
                'id'          => $pedido->folio,
                'title'       => "{$concepto} del pedido {$pedido->folio}",
                'quantity'    => 1,
                'currency_id' => $moneda,
                'unit_price'  => (float) $pago->monto,
            ]],
            'external_reference' => $pago->referenciaMercadoPago(),
            'metadata' => [
                'pago_id'          => $pago->id,
                'pedido_id'        => $pedido->id,
                'distribuidora_id' => $pedido->distribuidora_id,
                'tipo'             => $tipo,
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

        // TG-228 (G9): Mercado Pago avisa aquí cuando el pago cambia.
        // source_news=webhooks: solo el formato nuevo (con firma), no el IPN.
        // d: la distribuidora, solo como pista para avisos sin user_id (todo
        // se vuelve a revisar con la API de Mercado Pago).
        if (Route::has('mercado-pago.webhook')) {
            $aviso = route('mercado-pago.webhook', ['source_news' => 'webhooks', 'd' => $pedido->distribuidora_id]);

            if (str_starts_with($aviso, 'https://')) {
                $preferencia['notification_url'] = $aviso;
            }
        }

        return $preferencia;
    }
}
