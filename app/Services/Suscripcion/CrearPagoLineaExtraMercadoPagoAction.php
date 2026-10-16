<?php

namespace App\Services\Suscripcion;

use App\Models\Distribuidora;
use App\Models\Pago;
use App\Models\Suscripcion;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Services\MercadoPago\ClienteMercadoPago;
use App\Services\MercadoPago\MercadoPagoException;
use App\Services\MercadoPago\TokenMercadoPagoPlataforma;
use App\Support\FolioPago;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Cobro inmediato de una línea extra: solo el precio de esa línea.
 *
 * No alarga el periodo ya pagado. La siguiente mensualidad suma esta línea
 * al plan, salvo que cambien de plan (ahí el precio y el conteo se recalculan).
 */
class CrearPagoLineaExtraMercadoPagoAction
{
    private const MINUTOS_EN_PREPARACION = 2;

    private const MINUTOS_MARGEN_VIGENCIA = 10;

    public function __construct(
        private readonly ClienteMercadoPago $cliente,
        private readonly TokenMercadoPagoPlataforma $plataforma,
        private readonly RegistrarAuditoriaAction $auditoria,
    ) {
    }

    /**
     * @return array{pago: Pago, init_point: string, reutilizado: bool, monto: float}
     */
    public function ejecutar(int $distribuidoraId): array
    {
        return Tenant::forzar($distribuidoraId, function () use ($distribuidoraId) {
            $distribuidora = Distribuidora::query()->findOrFail($distribuidoraId);
            $suscripcion = Suscripcion::query()->where('estado', 'activa')->latest('id')->first();

            $fin = $suscripcion?->fecha_fin?->copy()->startOfDay();
            $vencida = $suscripcion !== null && ($suscripcion->estado !== 'activa' || ($fin !== null && $fin->lt(today())));

            if ($distribuidora->estado !== 'activa' || $suscripcion === null || $vencida) {
                throw MercadoPagoException::con(
                    $vencida
                        ? 'Para agregar una línea extra tu suscripción tiene que estar activa.'
                        : 'Para agregar una línea extra tu distribuidora necesita una suscripción activa.'
                );
            }

            $monto = round((float) $suscripcion->precio_linea_extra_contratado, 2);

            if ($monto <= 0) {
                throw MercadoPagoException::con('Tu plan no cobra las líneas extra.');
            }

            ['token' => $token] = $this->plataforma->para();

            [$pago, $reutilizado, $aVencer] = DB::transaction(fn () => $this->reservar($distribuidora, $suscripcion, $monto));

            if ($reutilizado) {
                $preferencia = $this->traducir(fn () => $this->cliente->obtenerPreferencia($token, $pago->preferencia_externa));

                return ['pago' => $pago, 'init_point' => $preferencia['init_point'], 'reutilizado' => true, 'monto' => $monto];
            }

            foreach ($aVencer as $preferenciaVieja) {
                try {
                    $this->cliente->expirarPreferencia($token, $preferenciaVieja);
                } catch (MercadoPagoException) {
                }
            }

            try {
                $preferencia = $this->traducir(fn () => $this->cliente->crearPreferencia(
                    $token,
                    $this->preferencia($distribuidora, $pago, $monto)
                ));
            } catch (MercadoPagoException $e) {
                $pago->update(['estado' => 'fallido']);

                throw $e;
            }

            $pago->update(['preferencia_externa' => $preferencia['id']]);

            $this->auditoria->ejecutar('pago.mercado_pago.preferencia', 'pago', $pago->id, null, [
                'suscripcion_id' => $pago->suscripcion_id,
                'folio' => $pago->folio,
                'tipo' => 'linea_extra',
                'monto' => $monto,
                'preferencia' => $preferencia['id'],
            ]);

            return ['pago' => $pago, 'init_point' => $preferencia['init_point'], 'reutilizado' => false, 'monto' => $monto];
        });
    }

    /** @return array{0: Pago, 1: bool, 2: list<string>} */
    private function reservar(Distribuidora $distribuidora, Suscripcion $suscripcion, float $monto): array
    {
        $suscripcion = Suscripcion::query()->whereKey($suscripcion->id)->lockForUpdate()->firstOrFail();

        $pendientes = Pago::query()
            ->where('tipo', 'suscripcion')
            ->where('referencia', Pago::REFERENCIA_LINEA_EXTRA)
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
        }

        $pago = Pago::create([
            'distribuidora_id' => $distribuidora->id,
            'pedido_id' => null,
            'venta_directa_id' => null,
            'suscripcion_id' => $suscripcion->id,
            'folio' => FolioPago::siguiente((int) $distribuidora->id),
            'tipo' => 'suscripcion',
            'direccion' => 'entrada',
            'metodo' => 'mercado_pago',
            'monto' => $monto,
            'fecha_pago' => now(),
            'referencia' => Pago::REFERENCIA_LINEA_EXTRA,
            'proveedor_pago' => 'mercado_pago',
            'referencia_externa' => null,
            'preferencia_externa' => null,
            'estado' => 'pendiente',
            'registrado_por_staff_id' => null,
        ]);

        return [$pago, false, $aVencer];
    }

    /** @return array<string, mixed> */
    private function preferencia(Distribuidora $distribuidora, Pago $pago, float $monto): array
    {
        $preferencia = [
            'items' => [[
                'id' => 'LINEA-EXTRA-'.$pago->suscripcion_id,
                'title' => 'Línea extra FootwearPoint',
                'description' => $distribuidora->nombre_comercial,
                'quantity' => 1,
                'currency_id' => CrearPagoSuscripcionMercadoPagoAction::MONEDA,
                'unit_price' => $monto,
            ]],
            'external_reference' => $pago->referenciaMercadoPago(),
            'metadata' => [
                'pago_id' => $pago->id,
                'suscripcion_id' => $pago->suscripcion_id,
                'distribuidora_id' => $distribuidora->id,
                'tipo' => 'linea_extra',
            ],
            'binary_mode' => true,
            'payment_methods' => [
                'excluded_payment_types' => [['id' => 'ticket'], ['id' => 'atm']],
            ],
            'expires' => true,
            'expiration_date_from' => $pago->created_at->format('Y-m-d\TH:i:s.vP'),
            'expiration_date_to' => $pago->venceMercadoPagoAt()->format('Y-m-d\TH:i:s.vP'),
        ];

        $retorno = route('distribuidora.configuracion', ['pestana' => 'suscripcion']);
        if (str_starts_with($retorno, 'https://')) {
            $preferencia['back_urls'] = ['success' => $retorno, 'pending' => $retorno, 'failure' => $retorno];
            $preferencia['auto_return'] = 'approved';
        }

        if (Route::has('mercado-pago.webhook')) {
            $aviso = route('mercado-pago.webhook', ['source_news' => 'webhooks']);

            if (str_starts_with($aviso, 'https://')) {
                $preferencia['notification_url'] = $aviso;
            }
        }

        return $preferencia;
    }

    private function traducir(callable $llamada): mixed
    {
        try {
            return $llamada();
        } catch (MercadoPagoException $e) {
            throw match ($e->getMessage()) {
                MercadoPagoException::SIN_RESPUESTA => $e,
                MercadoPagoException::CUENTA_DESCONECTADA => MercadoPagoException::con(MercadoPagoException::SUSCRIPCION_NO_DISPONIBLE),
                default => MercadoPagoException::con('No se pudo cobrar la línea extra. Intenta de nuevo en unos minutos.'),
            };
        }
    }
}
