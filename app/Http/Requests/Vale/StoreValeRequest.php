<?php

namespace App\Http\Requests\Vale;

use App\Support\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreValeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && Tenant::id() !== null;
    }

    public function rules(): array
    {
        return [
            'propietario_tipo' => ['required', 'string', Rule::in(['cliente_directo', 'revendedor'])],
            'propietario_id'   => ['required', 'integer', 'min:1'],
            'monto_original'   => ['required', 'numeric', 'min:0.01'],
            'motivo'           => ['nullable', 'string', 'max:300'],
        ];
    }

    public function messages(): array
    {
        return [
            'propietario_tipo.required' => 'Indica si el propietario es cliente_directo o revendedor.',
            'propietario_tipo.in'       => 'propietario_tipo debe ser cliente_directo o revendedor.',
            'propietario_id.required'   => 'El id del propietario es obligatorio.',
            'monto_original.required'   => 'El monto es obligatorio.',
            'monto_original.min'        => 'El monto debe ser mayor a cero.',
        ];
    }
}