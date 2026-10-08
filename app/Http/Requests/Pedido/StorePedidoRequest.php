<?php

namespace App\Http\Requests\Pedido;

use App\Support\PropietarioActual;
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
        // Solo el personal los tiene que mandar (TG-166). Si el pedido lo crea
        // el propio revendedor o cliente desde la app, el servidor pone el
        // tipo, el dueño y la sucursal, e ignora lo que venga.
        $soloPersonal = Rule::requiredIf(fn () => PropietarioActual::esDeLaCasa());

        return [
            'tipo' => [$soloPersonal, 'nullable', 'string', Rule::in(['cliente_directo', 'revendedor'])],
            'propietario_id' => [$soloPersonal, 'nullable', 'integer', 'min:1'],
            'sucursal_id' => [$soloPersonal, 'nullable', 'integer', 'min:1'],
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