<?php

namespace App\Http\Requests\Distribuidora;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ActualizarEmpleadoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin_distribuidora') ?? false;
    }

    /**
     * Alcance acordado con Paquete A: aquí solo se activa/desactiva al
     * empleado. NO se crean cuentas, NO se tocan contraseñas, NO se cambia
     * "tipo" (administrador/empleado) — eso se queda del lado de A.
     */
    public function rules(): array
    {
        return [
            'estado' => ['required', Rule::in(['activo', 'inactivo'])],
        ];
    }
}
