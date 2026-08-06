<?php

namespace App\Http\Requests\Pedido;

use App\Support\Tenant;
use Illuminate\Foundation\Http\FormRequest;

class AgregarLineaPedidoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && Tenant::id() !== null;
    }

    public function rules(): array
    {
        return [
            'producto_campana_id' => ['required', 'integer', 'min:1'],
            'variante_id'         => ['required', 'integer', 'min:1'],
            'cantidad'            => ['required', 'integer', 'min:1'],
            'precio_unitario'     => ['nullable', 'numeric', 'min:0'],
        ];
    }
}