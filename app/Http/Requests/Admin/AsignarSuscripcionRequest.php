<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AsignarSuscripcionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'plan_id'                  => ['required', 'integer', 'exists:planes_suscripcion,id'],
            'lineas_extra_contratadas' => ['nullable', 'integer', 'min:0'],
            'renovacion_automatica'    => ['nullable', 'boolean'],
            'meses'                    => ['nullable', 'integer', 'min:1', 'max:24'],
        ];
    }

    public function messages(): array
    {
        return [
            'plan_id.required' => 'Debes indicar el plan.',
            'plan_id.exists'   => 'El plan no existe.',
        ];
    }
}