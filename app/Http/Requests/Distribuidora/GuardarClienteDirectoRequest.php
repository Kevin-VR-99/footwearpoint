<?php

namespace App\Http\Requests\Distribuidora;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarClienteDirectoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin_distribuidora') ?? false;
    }

    public function rules(): array
    {
        $requerido = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'nombre'             => [$requerido, 'string', 'max:150'],
            'telefono'           => ['nullable', 'string', 'max:30'],
            'email'              => ['nullable', 'email', 'max:190'],
            'direccion_contacto' => ['nullable', 'string', 'max:300'],
            'notas'              => ['nullable', 'string'],
            'estado'             => ['sometimes', Rule::in(['activo', 'inactivo'])],
        ];
    }
}
