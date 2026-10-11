<?php

namespace App\Http\Controllers\Distribuidora;

use App\Http\Controllers\Controller;
use App\Services\MercadoPago\MercadoPagoException;
use App\Services\MercadoPago\VincularMercadoPagoService;
use App\Support\MensajeError;
use App\Support\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * TG-225 (G6) — Ida y vuelta con la pantalla oficial de Mercado Pago.
 *
 * Siempre regresa a Configuración → Mercado Pago con un mensaje en español;
 * los detalles técnicos solo van al log.
 */
class MercadoPagoOAuthController extends Controller
{
    public function conectar(Request $request, VincularMercadoPagoService $servicio): RedirectResponse
    {
        try {
            $url = $servicio->iniciar($request->user(), $this->distribuidoraId());
        } catch (MercadoPagoException $e) {
            return $this->volver('error', $e->getMessage());
        }

        return redirect()->away($url);
    }

    public function callback(Request $request, VincularMercadoPagoService $servicio): RedirectResponse
    {
        try {
            $servicio->completar($request->query(), $request->user(), $this->distribuidoraId());
        } catch (MercadoPagoException $e) {
            return $this->volver('error', $e->getMessage());
        } catch (Throwable $e) {
            return $this->volver('error', MensajeError::paraUsuario(
                $e,
                'No se pudo conectar tu cuenta de Mercado Pago. Intenta de nuevo.'
            ));
        }

        return $this->volver('exito', 'Listo: tu cuenta de Mercado Pago quedó conectada.');
    }

    private function volver(string $tipo, string $mensaje): RedirectResponse
    {
        return redirect()
            ->route('distribuidora.configuracion', ['pestana' => 'mercado-pago'])
            ->with("mercado_pago_{$tipo}", $mensaje);
    }

    private function distribuidoraId(): int
    {
        $id = Tenant::id();

        abort_if($id === null, 403, 'No se pudo determinar la distribuidora.');

        return $id;
    }
}
