<?php

namespace App\Services\Suscripcion;

use App\Models\Distribuidora;
use App\Models\Pago;
use App\Models\Suscripcion;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Services\Distribuidora\CupoLineasDistribuidora;
use App\Services\MercadoPago\ClienteMercadoPago;
use App\Services\MercadoPago\MercadoPagoException;
use App\Services\MercadoPago\TokenMercadoPagoPlataforma;
use App\Support\FolioPago;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * TG-230 (G11) — La distribuidora paga su mensualidad a FootwearPoint con
 * Checkout Pro, desde el panel (Configuración > Suscripción).
 *
 * El dinero va a la cuenta de FootwearPoint: la preferencia se crea con
 * MP_ACCESS_TOKEN (TokenMercadoPagoPlataforma), nunca con el token de la
 * distribuidora. Se crea un pago 'suscripcion' pendiente ligado a la
 * suscripción; la renovación la hace AplicarPagoSuscripcionMercadoPagoAction
 * cuando Mercado Pago confirma (aviso de G9, regreso o "verificar").
 *
 * Reglas:
 *   - la distribuidora debe estar activa;
 *   - se paga la suscripción activa o vencida más reciente;
 *   - un mes a la vez y como máximo un mes por adelantado (fecha_fin ≤ hoy + 1 mes);
 *   - monto = precio base contratado + líneas activas por encima de las
 *     incluidas × precio por línea extra contratado (la misma cuenta de
 *     CobroSuscripcion, pero para esta suscripción aunque esté vencida);
 *   - un solo enlace vivo: si ya hay uno vigente por el mismo monto se
 *     reutiliza; si no, el anterior se vence y se crea otro.
 */
class CrearPagoSuscripcionMercadoPagoAction
{
    public const MONEDA = 'MXN';

    /** Estados de la suscripción que se pueden pagar. */
    public const ESTADOS_PAGABLES = ['activa', 'vencida'];

    private const MINUTOS_EN_PREPARACION = 2;

    private const MINUTOS_MARGEN_VIGENCIA = 10;

    public function __construct(
        private readonly ClienteMercadoPago $cliente,
        private readonly TokenMercadoPagoPlataforma $plataforma,
        private readonly RegistrarAuditoriaAction $auditoria,
    ) {
    }

    /**
     * @return array{pago: Pago, init_point: string, reutilizado: bool}
     *
     * @throws MercadoPagoException con un mensaje listo para la distribuidora.
     */
    public function ejecutar(int $distribuidoraId): array
    {
        return Tenant::forzar($distribuidoraId, function () use ($distribuidoraId) {
            $distribuidora = Distribuidora::query()->findOrFail($distribuidoraId);

            $motivo = self::motivoParaNoCobrar($distribuidora, self::suscripcionPorPagar());
            if ($motivo !== null) {
                throw MercadoPagoException::con($motivo);
            }

            ['token' => $token] = $this->plataforma->para();

            [$pago, $reutilizado, $aVencer] = DB::transaction(fn () => $this->reservarPago($distribuidora));

            if ($reutilizado) {
                $preferencia = $this->traducir(fn () => $this->cliente->obtenerPreferencia($token, $pago->preferencia_externa));

                return ['pago' => $pago, 'init_point' => $preferencia['init_point'], 'reutilizado' => true];
            }

            foreach ($aVencer as $preferenciaVieja) {
                try {
                    $this->cliente->expirarPreferencia($token, $preferenciaVieja);
                } catch (MercadoPagoException) {
                    // Si llega a pagarse un enlace viejo, se aplica igual.
                }
            }

            try {
                $preferencia = $this->traducir(fn () => $this->cliente->crearPreferencia(
                    $token,
                    $this->preferencia($distribuidora, $pago)
                ));
            } catch (MercadoPagoException $e) {
                $pago->update(['estado' => 'fallido']);

                throw $e;
            }

            $pago->update(['preferencia_externa' => $preferencia['id']]);

            $this->auditoria->ejecutar('pago.mercado_pago.preferencia', 'pago', $pago->id, null, [
                'suscripcion_id' => $pago->suscripcion_id,
                'folio'          => $pago->folio,
                'tipo'           => 'suscripcion',
                'monto'          => (float) $pago->monto,
                'preferencia'    => $preferencia['id'],
                'reemplaza_a'    => $aVencer,
            ]);

            return ['pago' => $pago, 'init_point' => $preferencia['init_point'], 'reutilizado' => false];
        });
    }

    // ---------------------------------------------------------------
    // Reglas (también las usa la pantalla para mostrar el botón)
    // ---------------------------------------------------------------

    /** La suscripción que se pagaría: la activa o vencida más reciente (con el tenant puesto). */
    public static function suscripcionPorPagar(): ?Suscripcion
    {
        return Suscripcion::query()
            ->with('plan')
            ->whereIn('estado', self::ESTADOS_PAGABLES)
            ->latest('id')
            ->first();
    }

    /** Por qué todavía no se puede pagar la mensualidad, o null si sí. */
    public static function motivoParaNoCobrar(Distribuidora $distribuidora, ?Suscripcion $suscripcion): ?string
    {
        if ($distribuidora->estado !== 'activa') {
            return MercadoPagoException::DISTRIBUIDORA_NO_ACTIVA;
        }

        if ($suscripcion === null) {
            return MercadoPagoException::SIN_SUSCRIPCION_POR_PAGAR;
        }

        $fin = $suscripcion->fecha_fin?->copy()->startOfDay();

        if ($fin !== null && $fin->gt(today()->addMonthNoOverflow())) {
            return sprintf(MercadoPagoException::SUSCRIPCION_ADELANTADA, $fin->copy()->subMonthNoOverflow()->format('d/m/Y'));
        }

        return null;
    }

    /**
     * Lo que cuesta el siguiente mes de esta suscripción.
     *
     * @return array{base: float, lineas_extra: int, precio_linea_extra: float, total: float}
     */
    public static function monto(Suscripcion $suscripcion): array
    {
        $extras = max(0, app(CupoLineasDistribuidora::class)->activas() - (int) $suscripcion->lineas_incluidas_contratadas);
        $base = round((float) $suscripcion->precio_base_contratado, 2);
        $precioExtra = round((float) $suscripcion->precio_linea_extra_contratado, 2);

        return [
            'base'               => $base,
            'lineas_extra'       => $extras,
            'precio_linea_extra' => $precioExtra,
            'total'              => round($base + $extras * $precioExtra, 2),
        ];
    }

    /**
     * El periodo que queda pagado: un mes desde que termina el actual, o
     * desde hoy si ya venció.
     *
     * @return array{desde: Carbon, hasta: Carbon}
     */
    public static function periodoAlPagar(Suscripcion $suscripcion): array
    {
        $fin = $suscripcion->fecha_fin?->copy()->startOfDay();
        $vigente = $suscripcion->estado === 'activa' && $fin !== null && $fin->gte(today());
        $desde = $vigente ? $fin : today();

        return ['desde' => $desde, 'hasta' => $desde->copy()->addMonthNoOverflow()];
    }

    // ---------------------------------------------------------------

    /** @return array{0: Pago, 1: bool, 2: list<string>} */
    private function reservarPago(Distribuidora $distribuidora): array
    {
        // Se vuelve a revisar con la suscripción bloqueada.
        $suscripcion = Suscripcion::query()
            ->whereIn('estado', self::ESTADOS_PAGABLES)
            ->latest('id')
            ->lockForUpdate()
            ->first();

        $motivo = self::motivoParaNoCobrar($distribuidora, $suscripcion);
        if ($motivo !== null) {
            throw MercadoPagoException::con($motivo);
        }

        $monto = self::monto($suscripcion)['total'];

        if ($monto <= 0) {
            throw MercadoPagoException::con(MercadoPagoException::SUSCRIPCION_NO_SE_PUDO_COBRAR);
        }

        // Todos los pagos de mensualidad con Mercado Pago pendientes: solo
        // debe quedar un enlace vivo.
        $pendientes = Pago::query()
            ->where('tipo', 'suscripcion')
            ->where('metodo', 'mercado_pago')
            ->where('estado', 'pendiente')
            ->orderByDesc('id')
            ->get();

        if ($pendientes->contains(fn (Pago $p) => $p->preferencia_externa === null
            && $p->created_at->gt(now()->subMinutes(self::MINUTOS_EN_PREPARACION)))) {
            throw MercadoPagoException::con(MercadoPagoException::PAGO_EN_PREPARACION);
        }

        $vigente = $pendientes->first(fn (Pago $p) => (int) $p->suscripcion_id === (int) $suscripcion->id
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

            $this->auditoria->ejecutar('pago.mercado_pago.reemplazado', 'pago', $anterior->id,
                ['estado' => 'pendiente'],
                ['estado' => 'fallido', 'suscripcion_id' => $anterior->suscripcion_id]);
        }

        $pago = Pago::create([
            'distribuidora_id'        => $distribuidora->id,
            'pedido_id'               => null,
            'venta_directa_id'        => null,
            'suscripcion_id'          => $suscripcion->id,
            'folio'                   => FolioPago::siguiente((int) $distribuidora->id),
            'tipo'                    => 'suscripcion',
            'direccion'               => 'entrada',
            'metodo'                  => 'mercado_pago',
            'monto'                   => $monto,
            'fecha_pago'              => now(),
            'referencia'              => null,
            'proveedor_pago'          => 'mercado_pago',
            'referencia_externa'      => null,
            'preferencia_externa'     => null,
            'estado'                  => 'pendiente',
            'registrado_por_staff_id' => null,
        ]);

        return [$pago, false, $aVencer];
    }

    /** Cuerpo de POST /checkout/preferences. */
    private function preferencia(Distribuidora $distribuidora, Pago $pago): array
    {
        $plan = $pago->suscripcion?->plan?->nombre;

        $preferencia = [
            'items' => [[
                'id'          => 'SUSCRIPCION-'.$pago->suscripcion_id,
                'title'       => 'Mensualidad FootwearPoint'.($plan ? " - Plan {$plan}" : ''),
                'description' => $distribuidora->nombre_comercial,
                'quantity'    => 1,
                'currency_id' => self::MONEDA,
                'unit_price'  => (float) $pago->monto,
            ]],
            'external_reference' => $pago->referenciaMercadoPago(),
            'metadata' => [
                'pago_id'          => $pago->id,
                'suscripcion_id'   => $pago->suscripcion_id,
                'distribuidora_id' => $distribuidora->id,
                'tipo'             => 'suscripcion',
            ],
            'binary_mode' => true,
            'payment_methods' => [
                'excluded_payment_types' => [['id' => 'ticket'], ['id' => 'atm']],
            ],
            'expires'              => true,
            'expiration_date_from' => $pago->created_at->format('Y-m-d\TH:i:s.vP'),
            'expiration_date_to'   => $pago->venceMercadoPagoAt()->format('Y-m-d\TH:i:s.vP'),
        ];

        // De regreso, a la misma pestaña (ahí se confirma el pago).
        $retorno = route('distribuidora.configuracion', ['pestana' => 'suscripcion']);
        if (str_starts_with($retorno, 'https://')) {
            $preferencia['back_urls'] = ['success' => $retorno, 'pending' => $retorno, 'failure' => $retorno];
            $preferencia['auto_return'] = 'approved';
        }

        // El aviso llega con user_id = la cuenta de FootwearPoint, y así G9
        // sabe que es una mensualidad (sin la pista ?d=).
        if (Route::has('mercado-pago.webhook')) {
            $aviso = route('mercado-pago.webhook', ['source_news' => 'webhooks']);

            if (str_starts_with($aviso, 'https://')) {
                $preferencia['notification_url'] = $aviso;
            }
        }

        return $preferencia;
    }

    /**
     * Los mensajes del cliente de Mercado Pago hablan del cobro de pedidos
     * ("paga en mostrador", "la distribuidora debe reconectar"): aquí se
     * cambian por los de la mensualidad.
     *
     * @template T
     *
     * @param  callable(): T  $llamada
     * @return T
     */
    private function traducir(callable $llamada): mixed
    {
        try {
            return $llamada();
        } catch (MercadoPagoException $e) {
            throw match ($e->getMessage()) {
                MercadoPagoException::SIN_RESPUESTA => $e,
                MercadoPagoException::CUENTA_DESCONECTADA => MercadoPagoException::con(MercadoPagoException::SUSCRIPCION_NO_DISPONIBLE),
                default => MercadoPagoException::con(MercadoPagoException::SUSCRIPCION_NO_SE_PUDO_COBRAR),
            };
        }
    }
}
