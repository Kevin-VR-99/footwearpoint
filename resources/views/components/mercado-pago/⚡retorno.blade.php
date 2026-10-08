<?php

use App\Models\Pago;
use App\Services\Pago\VerificarPagoMercadoPagoAction;
use App\Support\Tenant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| TG-226 (G7) — Página a la que regresa Mercado Pago después de pagar.
|
| El "status" de la URL solo cambia el texto. Bugfix: si la URL trae
| payment_id y external_reference (FWP-{distribuidora}-{pago}), se confirma
| el pago aquí mismo, pero sin creerle a la URL: solo dicen dónde preguntar.
| VerificarPagoMercadoPagoAction consulta GET /v1/payments/{payment_id} con
| el token de la distribuidora y aplica solo si lo que contesta Mercado Pago
| es de ese pago (referencia, monto, moneda y cuenta).
|
| Es pública: tiene límite por IP y por pago, y nunca le muestra un error a
| quien la abre (si algo falla, queda en el log y la página sigue igual).
*/
new #[Layout('layouts.guest')] #[Title('Pago con Mercado Pago — FootwearPoint')] class extends Component {
    /** Intentos de confirmar por minuto, por IP y por pago. */
    private const LIMITE_POR_IP = 10;

    private const LIMITE_POR_PAGO = 5;

    public string $resultado = 'otro';

    /** TG-227 (G8): tipo del pago que regresó ('anticipo', 'saldo_pedido' o '' si no se sabe). */
    public string $tipo = '';

    public function mount(): void
    {
        $status = request()->query('status', request()->query('collection_status'));

        $this->resultado = match ($status) {
            'approved' => 'aprobado',
            'pending', 'in_process' => 'en_proceso',
            'rejected', 'cancelled', 'null' => 'no_completado',
            default => 'otro',
        };

        if ($this->resultado !== 'no_completado' && $this->confirmar()) {
            $this->resultado = 'recibido';
        }
    }

    /** true si el pago (anticipo o saldo) quedó aplicado con este payment_id. */
    private function confirmar(): bool
    {
        $pagoMpId = request()->query('payment_id', request()->query('collection_id'));
        $referencia = request()->query('external_reference');

        if (! is_string($pagoMpId) || preg_match('/^[0-9]{1,20}$/', $pagoMpId) !== 1
            || ! is_string($referencia) || preg_match('/^FWP-([0-9]{1,10})-([0-9]{1,12})$/', $referencia, $partes) !== 1) {
            return false;
        }

        [, $distribuidoraId, $pagoId] = array_map('intval', $partes);

        if (! $this->dentroDelLimite('mp-retorno:ip:'.request()->ip(), self::LIMITE_POR_IP)
            || ! $this->dentroDelLimite('mp-retorno:pago:'.$pagoId, self::LIMITE_POR_PAGO)) {
            Log::warning('Mercado Pago: la página de regreso llegó al límite de confirmaciones.', ['pago_id' => $pagoId]);

            return false;
        }

        try {
            return Tenant::forzar($distribuidoraId, function () use ($distribuidoraId, $pagoId, $pagoMpId) {
                $pago = Pago::query()
                    ->where('distribuidora_id', $distribuidoraId)
                    ->whereKey($pagoId)
                    ->where('metodo', 'mercado_pago')
                    ->whereNotNull('preferencia_externa')
                    ->first();

                if ($pago === null) {
                    return false;
                }

                $this->tipo = (string) $pago->tipo;

                // Ya se había confirmado con este mismo pago de Mercado Pago.
                if ($pago->estado === 'aplicado') {
                    return $pago->referencia_externa === $pagoMpId;
                }

                if ($pago->estado !== 'pendiente') {
                    return false;
                }

                return app(VerificarPagoMercadoPagoAction::class)->verificarPago($pago, $pagoMpId, 'retorno')
                    === VerificarPagoMercadoPagoAction::APLICADO;
            });
        } catch (\Throwable $e) {
            // Quien regresa de pagar nunca ve un error: la app y el panel
            // pueden volver a verificar.
            Log::warning('Mercado Pago: la página de regreso no pudo confirmar el pago.', [
                'pago_id' => $pagoId,
                'error'   => class_basename($e),
                'mensaje' => $e instanceof \App\Exceptions\MensajeParaUsuario ? $e->getMessage() : null,
            ]);

            return false;
        }
    }

    private function dentroDelLimite(string $llave, int $maximo): bool
    {
        if (RateLimiter::tooManyAttempts($llave, $maximo)) {
            return false;
        }

        RateLimiter::hit($llave, 60);

        return true;
    }
};
?>

<div class="rounded-xl border border-slate-200 bg-white p-6 text-center shadow-sm">
    <p class="text-xs font-semibold uppercase tracking-wider text-fp-primary">FootwearPoint</p>

    @if ($resultado === 'recibido')
        <h1 class="mt-2 text-xl font-semibold text-slate-900">{{ $tipo === 'saldo_pedido' ? '¡Recibimos el pago de tu saldo!' : '¡Recibimos tu anticipo!' }}</h1>
        <p class="mt-2 text-sm text-slate-600">Mercado Pago confirmó tu pago y ya quedó registrado en tu pedido.</p>
    @elseif ($resultado === 'aprobado')
        <h1 class="mt-2 text-xl font-semibold text-slate-900">¡Gracias por tu pago!</h1>
        <p class="mt-2 text-sm text-slate-600">Mercado Pago está confirmando tu pago con la distribuidora.</p>
    @elseif ($resultado === 'en_proceso')
        <h1 class="mt-2 text-xl font-semibold text-slate-900">Tu pago está en proceso</h1>
        <p class="mt-2 text-sm text-slate-600">Mercado Pago todavía no lo confirma.</p>
    @elseif ($resultado === 'no_completado')
        <h1 class="mt-2 text-xl font-semibold text-slate-900">El pago no se completó</h1>
        <p class="mt-2 text-sm text-slate-600">No se te cobró nada. Puedes intentarlo de nuevo desde la app.</p>
    @else
        <h1 class="mt-2 text-xl font-semibold text-slate-900">Regresaste de Mercado Pago</h1>
    @endif

    <p class="mt-4 text-sm text-slate-600">
        Regresa a la app de FootwearPoint y abre tu pedido para ver el estado de {{ $tipo === 'anticipo' ? 'tu anticipo' : 'tu pago' }}.
    </p>
</div>
