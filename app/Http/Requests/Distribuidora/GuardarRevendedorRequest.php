<?php

namespace App\Http\Requests\Distribuidora;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarRevendedorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin_distribuidora') ?? false;
    }

    public function rules(): array
    {
        $requerido = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            // Estos 3 campos viven en la tabla GLOBAL "revendedores", no en
            // "revendedor_distribuidora" — ver LEEME de este bloque.
            'nombre'         => [$requerido, 'string', 'max:150'],
            'telefono'       => ['nullable', 'string', 'max:30'],
            'email'          => ['nullable', 'email', 'max:190'],

            // Estos sí viven en "revendedor_distribuidora" (la afiliación).
            'codigo_interno' => ['nullable', 'string', 'max:60'],
            'notas'          => ['nullable', 'string'],
            'estado'         => ['sometimes', Rule::in(['activo', 'suspendido', 'inactivo'])],
        ];
    }
}
