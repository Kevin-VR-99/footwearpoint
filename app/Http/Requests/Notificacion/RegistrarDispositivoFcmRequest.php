<?php

namespace App\Http\Requests\Notificacion;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegistrarDispositivoFcmRequest extends FormRequest
{
    // Quién puede ya lo decide la ruta (auth:sanctum): cada usuario registra
    // su propio celular.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token'      => ['required', 'string', 'max:255'],
            // Los mismos valores del enum de dispositivos_fcm.plataforma. Este
            // sprint solo hay Android, pero la columna ya acepta los tres.
            'plataforma' => ['required', Rule::in(['android', 'ios', 'web'])],
        ];
    }

    public function messages(): array
    {
        return [
            'token.required'      => 'Falta el token del dispositivo.',
            'plataforma.required' => 'Falta la plataforma del dispositivo.',
            'plataforma.in'       => 'La plataforma debe ser android, ios o web.',
        ];
    }
}
