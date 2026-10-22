<?php

namespace App\Livewire\Concerns;

use App\Services\Distribuidora\BajaAccesoAction;
use App\Support\MensajeError;

/**
 * Botones «Dar de baja» / «Reactivar» de las pantallas de clientes y
 * empleados. La regla vive en BajaAccesoAction; aquí solo se avisa.
 */
trait GestionaBajasDeAcceso
{
    public function darDeBaja(string $tipo, int $id): void
    {
        $this->aplicarCambioDeAcceso(fn (BajaAccesoAction $accion) => $accion->darDeBaja($tipo, $id), 'Acceso dado de baja. Su historial se conserva y ya no puede entrar a la app.');
    }

    public function reactivar(string $tipo, int $id): void
    {
        $this->aplicarCambioDeAcceso(fn (BajaAccesoAction $accion) => $accion->reactivar($tipo, $id), 'Acceso reactivado.');
    }

    private function aplicarCambioDeAcceso(callable $cambio, string $exito): void
    {
        try {
            $cambio(app(BajaAccesoAction::class));
            $this->dispatch('guardado', mensaje: $exito);

            if (method_exists($this, 'despuesDeCambioDeAcceso')) {
                $this->despuesDeCambioDeAcceso();
            }
        } catch (\Throwable $e) {
            $this->dispatch('error-negocio', mensaje: MensajeError::paraUsuario($e, 'No se pudo cambiar el acceso.'));
        }
    }
}
