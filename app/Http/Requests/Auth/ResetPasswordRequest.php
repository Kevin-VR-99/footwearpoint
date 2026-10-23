<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email'                 => ['required', 'email'],
            'token'                 => ['required', 'string', 'max:255'],
            'password'              => ['required', 'string', \Illuminate\Validation\Rules\Password::defaults(), 'max:255', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'token.max' => 'El token no es válido.',
            'password.max' => 'La contraseña no puede tener más de 255 caracteres.',
            'email.required'     => 'El correo es obligatorio.',
            'email.email'        => 'El correo no es válido.',
            'token.required'     => 'El token es obligatorio.',
            'password.required'  => 'La contraseña es obligatoria.',
            'password.min'       => 'La contraseña debe tener al menos 8 caracteres.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ];
    }
}