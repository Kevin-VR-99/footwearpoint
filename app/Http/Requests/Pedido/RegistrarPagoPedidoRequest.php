<?php

namespace App\Http\Requests\Pedido;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegistrarPagoPedidoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tipo' => ['required', Rule::in(['anticipo', 'saldo_pedido'])],
            'metodo' => ['required', Rule::in(['efectivo', 'transferencia', 'tarjeta', 'otro'])],
            'monto' => ['required', 'numeric', 'min:0.01'],
            'referencia' => ['nullable', 'string', 'max:190'],
        ];
    }
}