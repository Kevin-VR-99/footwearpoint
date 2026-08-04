<?php

namespace App\Http\Requests\Stock;

use Illuminate\Foundation\Http\FormRequest;

class IndexStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // La autorizacion real vive en StockLocalPolicy.
    }

    public function rules(): array
    {
        return [
            'variante_id' => ['nullable', 'integer', 'min:1'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}