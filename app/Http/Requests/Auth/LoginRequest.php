<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email'    => ['required', 'email'],
            'password' => ['required', 'string', 'max:255'],
            // Auditoría 21-oct-2026: nombre del teléfono, para saber de qué dispositivo es cada token.
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'device_name.max' => 'El nombre del dispositivo no puede tener más de 100 caracteres.',
            'password.max' => 'La contraseña no puede tener más de 255 caracteres.',
            'email.required'    => 'El correo es obligatorio.',
            'email.email'       => 'El correo no es válido.',
            'password.required' => 'La contraseña es obligatoria.',
        ];
    }
}