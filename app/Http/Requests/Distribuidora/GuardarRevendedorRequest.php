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

            // E3-07 (TG-133): cuenta de acceso a la app, opcional. Se puede
            // mandar al crear o después, en el PATCH. Los dos van juntos.
            'acceso_email'    => ['nullable', 'required_with:acceso_password', 'email', 'max:190'],
            'acceso_password' => ['nullable', 'required_with:acceso_email', 'string', 'min:8', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'acceso_email.required_with'    => 'Para dar acceso a la app escribe también el correo.',
            'acceso_password.required_with' => 'Para dar acceso a la app escribe también la contraseña.',
            'acceso_password.min'           => 'La contraseña debe tener al menos 8 caracteres.',
            'acceso_password.confirmed'     => 'La confirmación de la contraseña no coincide.',
        ];
    }
}
