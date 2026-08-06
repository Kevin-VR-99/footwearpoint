<?php

namespace App\Http\Requests\Vale;

use App\Support\Tenant;
use Illuminate\Foundation\Http\FormRequest;

class AplicarValeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && Tenant::id() !== null;
    }

    public function rules(): array
    {
        return [
            'monto' => ['required', 'numeric', 'min:0.01'],
            'pedido_id' => ['nullable', 'integer', 'min:1', 'required_without:venta_directa_id'],
            'venta_directa_id' => ['nullable', 'integer', 'min:1', 'required_without:pedido_id'],
        ];
    }
}