<?php

namespace App\Http\Requests\Cambio;

use Illuminate\Foundation\Http\FormRequest;

/** TG-254: POST /api/solicitudes-cambio. Las reglas de negocio viven en SolicitarCambioAction. */
class SolicitarCambioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pedido_detalle_id' => ['nullable', 'integer', 'min:1', 'required_without:venta_detalle_id', 'prohibits:venta_detalle_id'],
            'venta_detalle_id' => ['nullable', 'integer', 'min:1', 'required_without:pedido_detalle_id'],
            'motivo' => ['required', 'string', 'max:300'],
        ];
    }

    public function messages(): array
    {
        return [
            'pedido_detalle_id.required_without' => 'Indica la línea del pedido o la de la venta.',
            'venta_detalle_id.required_without' => 'Indica la línea del pedido o la de la venta.',
            'pedido_detalle_id.prohibits' => 'Indica la línea del pedido o la de la venta, solo una.',
            'motivo.required' => 'Escribe el motivo del cambio.',
            'motivo.max' => 'El motivo no puede pasar de 300 caracteres.',
        ];
    }
}
