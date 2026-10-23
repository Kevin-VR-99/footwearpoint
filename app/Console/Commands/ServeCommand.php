<?php

namespace App\Console\Commands;

use App\Support\OcultarTokensEnUrl;
use Illuminate\Foundation\Console\ServeCommand as ServeCommandBase;

/**
 * En Railway la web corre con "php artisan serve" y cada petición sale en el
 * log con su ruta completa. Esta versión oculta el token de
 * /reset-password/{token}, el hash y la firma del enlace de confirmación de
 * correo y cualquier ?token= / ?signature= antes de escribir la línea.
 */
class ServeCommand extends ServeCommandBase
{
    protected function handleProcessOutput()
    {
        return function ($type, $buffer) {
            $this->agregarSalida((string) $buffer);

            $this->flushOutputBuffer();
        };
    }

    /** Solo se ocultan líneas completas, para no partir un token entre dos lecturas. */
    public function agregarSalida(string $buffer): void
    {
        $this->outputBuffer .= $buffer;

        $corte = strrpos($this->outputBuffer, "\n");

        if ($corte === false) {
            return;
        }

        $this->outputBuffer = OcultarTokensEnUrl::texto(substr($this->outputBuffer, 0, $corte + 1))
            .substr($this->outputBuffer, $corte + 1);
    }

    public function salidaPendiente(): string
    {
        return $this->outputBuffer;
    }
}
