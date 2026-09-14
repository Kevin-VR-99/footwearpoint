<?php

namespace App\Http\Requests\Perfil;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/perfil/password (E1-05 / TG-110).
 *
 * La nueva contraseña sigue las mismas reglas mínimas que al restablecerla
 * por enlace (ResetPasswordRequest). Que la actual sea correcta lo revisa
 * CambiarPasswordAction, porque necesita al usuario.
 */
class CambiarPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password_actual' => ['required', 'string'],
            'password'        => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'password_actual.required' => 'Escribe tu contraseña actual.',
            'password.required'        => 'La contraseña es obligatoria.',
            'password.min'             => 'La contraseña debe tener al menos 8 caracteres.',
            'password.confirmed'       => 'Las contraseñas no coinciden.',
        ];
    }
}
