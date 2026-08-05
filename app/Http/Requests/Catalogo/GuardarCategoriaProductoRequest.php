<?php

namespace App\Http\Requests\Catalogo;

use Illuminate\Foundation\Http\FormRequest;

class GuardarCategoriaProductoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin_distribuidora') ?? false;
    }

    public function rules(): array
    {
        $requerido = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'nombre'      => [$requerido, 'string', 'max:120'],
            'descripcion' => ['nullable', 'string', 'max:300'],
            'activa'      => ['sometimes', 'boolean'],
        ];
    }
}
