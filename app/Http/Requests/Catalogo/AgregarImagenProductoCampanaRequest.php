<?php

namespace App\Http\Requests\Catalogo;

use Illuminate\Foundation\Http\FormRequest;

class AgregarImagenProductoCampanaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin_distribuidora') ?? false;
    }

    public function rules(): array
    {
        return [
            // Tamaño máximo SÍ está documentado para imágenes de producto
            // en el mockup de "Agregar Nuevo Producto": 5MB, PNG/JPG.
            'imagen'       => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
            'es_principal' => ['sometimes', 'boolean'],
        ];
    }
}
