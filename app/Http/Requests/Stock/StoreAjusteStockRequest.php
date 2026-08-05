<?php

namespace App\Http\Requests\Stock;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAjusteStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'variante_id' => ['required', 'integer', 'min:1'],
            'tipo' => ['required', Rule::in(['ajuste_positivo', 'ajuste_negativo'])],
            'cantidad' => ['required', 'integer', 'min:1'],
            'motivo' => ['required', 'string', 'max:300'],
        ];
    }

    public function messages(): array
    {
        return [
            'motivo.required' => 'Todo ajuste manual necesita un motivo (merma, correccion, etc.).',
        ];
    }
}