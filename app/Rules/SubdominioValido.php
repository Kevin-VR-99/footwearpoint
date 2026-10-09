<?php

namespace App\Rules;

use App\Models\Distribuidora;
use App\Services\Tienda\DominioTienda;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * TG-232 (E3-02) — Un subdominio de tienda válido: formato DNS, no reservado
 * y que no sea el slug de OTRA distribuidora (la tienda también se encuentra
 * por slug). Que no lo use otra como subdominio lo revisa Rule::unique.
 *
 * Se usa en el alta (admin general) y en el perfil (CambiarSubdominioAction).
 */
class SubdominioValido implements ValidationRule
{
    public function __construct(private readonly ?int $distribuidoraId = null)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match(DominioTienda::FORMATO, $value) !== 1) {
            $fail('El subdominio debe tener de 3 a 63 caracteres: solo letras minúsculas, números y guiones, sin guion al inicio ni al final.');

            return;
        }

        if (DominioTienda::esReservado($value)) {
            $fail('Ese subdominio está reservado. Elige otro.');

            return;
        }

        $esSlugDeOtra = Distribuidora::query()
            ->where('slug', $value)
            ->when($this->distribuidoraId !== null, fn ($q) => $q->whereKeyNot($this->distribuidoraId))
            ->exists();

        if ($esSlugDeOtra) {
            $fail('Ese subdominio ya lo usa otra distribuidora.');
        }
    }
}
