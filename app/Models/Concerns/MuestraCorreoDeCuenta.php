<?php

namespace App\Models\Concerns;

/**
 * El correo que hay que mostrar en el panel.
 *
 * Al dar la cuenta de la app, el correo de contacto se vacía y el que la
 * persona confirma queda en usuarios.email (ActivarCuentaAccesoAction).
 * Si la tabla sigue leyendo solo el de contacto, el renglón se ve vacío
 * aunque el correo ya esté confirmado.
 */
trait MuestraCorreoDeCuenta
{
    public function emailVisible(): ?string
    {
        $cuenta = trim((string) ($this->usuario?->email ?? ''));

        if ($cuenta !== '') {
            return $cuenta;
        }

        $contacto = trim((string) ($this->email ?? ''));

        return $contacto !== '' ? $contacto : null;
    }
}
