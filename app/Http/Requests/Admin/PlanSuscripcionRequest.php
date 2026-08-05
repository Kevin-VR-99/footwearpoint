<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlanSuscripcionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $planId = $this->route('id');

        return [
            'nombre'               => [
                'required',
                'string',
                'max:100',
                Rule::unique('planes_suscripcion', 'nombre')->ignore($planId),
            ],
            'descripcion'          => ['nullable', 'string'],
            'precio_base_mensual'  => ['required', 'numeric', 'min:0'],
            'lineas_incluidas'     => ['required', 'integer', 'min:0'],
            'precio_linea_extra'   => ['required', 'numeric', 'min:0'],
            'activo'               => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required'              => 'El nombre del plan es obligatorio.',
            'nombre.unique'                => 'Ya existe un plan con ese nombre.',
            'precio_base_mensual.required' => 'El precio base es obligatorio.',
            'lineas_incluidas.required'    => 'Las líneas incluidas son obligatorias.',
            'precio_linea_extra.required'  => 'El precio por línea extra es obligatorio.',
        ];
    }
}