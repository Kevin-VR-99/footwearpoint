<?php

namespace App\Rules;

use App\Services\Correo\VerificadorDominioCorreo;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * El correo de una cuenta nueva debe tener un dominio que exista (MX, A o
 * AAAA). Evita cuentas con correos inventados o mal escritos
 * (p. ej. "gmial.con"), a los que nunca llegaría la confirmación.
 *
 * La forma (algo@algo) la revisa la regla 'email'; esta solo mira el dominio.
 */
class CorreoConDominioReal implements ValidationRule
{
    public const MENSAJE = 'Ese correo no parece existir. Usa un correo válido.';

    public function __construct(private readonly ?VerificadorDominioCorreo $verificador = null)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $posicion = strrpos($value, '@');

        // Sin arroba ya lo rechaza la regla 'email'; aquí no se repite el aviso.
        if ($posicion === false) {
            return;
        }

        $dominio = substr(trim($value), strrpos(trim($value), '@') + 1);
        $verificador = $this->verificador ?? app(VerificadorDominioCorreo::class);

        if (! $verificador->existe($dominio)) {
            $fail(self::MENSAJE);
        }
    }
}
