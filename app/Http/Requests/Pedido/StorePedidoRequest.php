<?php

namespace App\Http\Requests\Pedido;

use App\Support\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePedidoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && Tenant::id() !== null;
    }

    public function rules(): array
    {
        return [
            'tipo' => ['required', 'string', Rule::in(['cliente_directo', 'revendedor'])],
            'propietario_id' => ['required', 'integer', 'min:1'],
            'sucursal_id' => ['required', 'integer', 'min:1'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'tipo.required' => 'Indica si el pedido es de cliente_directo o revendedor.',
            'tipo.in' => 'tipo debe ser cliente_directo o revendedor.',
            'propietario_id.required' => 'El propietario es obligatorio.',
            'sucursal_id.required' => 'La sucursal es obligatoria.',
        ];
    }
}