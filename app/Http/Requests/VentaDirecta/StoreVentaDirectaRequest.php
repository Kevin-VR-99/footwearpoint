<?php

namespace App\Http\Requests\VentaDirecta;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVentaDirectaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // La autorizacion real vive en VentaDirectaPolicy.
    }

    public function rules(): array
    {
        return [
            'cliente_directo_id' => ['nullable', 'integer', 'min:1'],

            // Valores del enum real de pagos.metodo.
            'metodo_pago' => ['required', Rule::in([
                'efectivo', 'transferencia', 'tarjeta', 'mercado_pago', 'otro',
            ])],

            'lineas' => ['required', 'array', 'min:1'],
            'lineas.*.variante_id' => ['required', 'integer', 'min:1', 'distinct'],
            'lineas.*.producto_campana_id' => ['required', 'integer', 'min:1'],
            'lineas.*.cantidad' => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'metodo_pago.required' => 'Indica con que metodo se cobro la venta.',
            'lineas.required' => 'La venta necesita al menos un producto.',
            'lineas.*.variante_id.distinct' => 'Cada variante debe aparecer una sola vez; sumala en la cantidad.',
            'lineas.*.cantidad.min' => 'La cantidad vendida debe ser mayor que cero.',
        ];
    }
}