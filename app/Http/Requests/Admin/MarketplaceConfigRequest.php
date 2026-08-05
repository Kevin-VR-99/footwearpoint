<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class MarketplaceConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'distribuidora_id'     => ['required', 'integer', 'exists:distribuidoras,id'],
            'marketplace_visible'  => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'distribuidora_id.required'    => 'La distribuidora es obligatoria.',
            'distribuidora_id.exists'      => 'La distribuidora no existe.',
            'marketplace_visible.required' => 'Debes indicar si será visible en el marketplace.',
        ];
    }
}