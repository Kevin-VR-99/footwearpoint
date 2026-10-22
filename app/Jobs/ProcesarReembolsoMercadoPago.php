<?php

namespace App\Jobs;

use App\Services\Reembolso\ReembolsoMercadoPagoService;
use App\Services\Reembolso\ReembolsoTemporalException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Pide en la cola el reembolso a Mercado Pago. Si Mercado Pago no responde
 * o contesta 5xx/429, la cola lo reintenta con espera creciente; siempre con
 * la MISMA llave de idempotencia, así que nunca se devuelve dos veces.
 * Si se acaban los intentos queda 'fallido' y el personal puede reintentar.
 */
class ProcesarReembolsoMercadoPago implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public int $reembolsoId)
    {
    }

    /** @return list<int> segundos entre intentos */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(ReembolsoMercadoPagoService $servicio): void
    {
        try {
            $servicio->procesar($this->reembolsoId);
        } catch (ReembolsoTemporalException $e) {
            $intento = $this->attempts();
            $sinCola = $this->job === null || $this->job->getConnectionName() === 'sync';

            // Con la cola síncrona (o en el último intento) nadie más lo
            // reintentaría: queda fallido y el personal decide.
            if ($sinCola || $intento >= $this->tries) {
                $servicio->marcarFallido($this->reembolsoId, $e->getMessage());

                return;
            }

            $esperas = $this->backoff();
            $this->release($esperas[min($intento - 1, count($esperas) - 1)]);
        }
    }

    public function failed(?Throwable $e): void
    {
        app(ReembolsoMercadoPagoService::class)->marcarFallido(
            $this->reembolsoId,
            'Mercado Pago no respondió después de varios intentos. Puedes reintentarlo.'
        );
    }
}
