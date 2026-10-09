<?php

namespace App\Services\Distribuidora;

use App\Models\Distribuidora;
use App\Rules\SubdominioValido;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * TG-232 (E3-02) — La distribuidora elige el subdominio de su tienda
 * ({subdominio}.footwearpoint.app). Solo su administrador (lo revisa el
 * perfil, que vive en la configuración de admin_distribuidora).
 *
 * Se guarda en minúsculas, con las reglas de SubdominioValido y único entre
 * distribuidoras. Queda en la bitácora con el valor anterior y el nuevo.
 */
class CambiarSubdominioAction
{
    public function __construct(private readonly RegistrarAuditoriaAction $auditoria)
    {
    }

    /** @throws ValidationException con el error en 'subdominio'. */
    public function ejecutar(Distribuidora $distribuidora, string $subdominio): Distribuidora
    {
        $subdominio = strtolower(trim($subdominio));

        Validator::make(
            ['subdominio' => $subdominio],
            ['subdominio' => [
                'required',
                'string',
                new SubdominioValido((int) $distribuidora->id),
                Rule::unique('distribuidoras', 'subdominio')->ignore($distribuidora->id),
            ]],
            [
                'subdominio.required' => 'Escribe el subdominio de tu tienda.',
                'subdominio.unique'   => 'Ese subdominio ya lo usa otra distribuidora.',
            ]
        )->validate();

        $anterior = $distribuidora->subdominio;

        if ($anterior === $subdominio) {
            return $distribuidora;
        }

        $distribuidora->forceFill(['subdominio' => $subdominio])->save();

        $this->auditoria->ejecutar(
            'distribuidora.subdominio_actualizado',
            'distribuidora',
            (int) $distribuidora->id,
            ['subdominio' => $anterior],
            ['subdominio' => $subdominio],
        );

        return $distribuidora;
    }
}
