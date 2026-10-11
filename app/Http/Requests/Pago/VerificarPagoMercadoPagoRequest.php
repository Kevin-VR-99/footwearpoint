<?php

namespace App\Http\Requests\Pago;

use Illuminate\Foundation\Http\FormRequest;

/**
 * TG-226 (bugfix) — La app puede mandar el payment_id que Mercado Pago puso
 * en la URL de regreso. Es opcional y solo dice dónde preguntar: el pago se
 * confirma con lo que contesta Mercado Pago.
 */
class VerificarPagoMercadoPagoRequest extends FormRequest
{
    // Quién puede ya lo deciden la ruta y el controlador (sus pedidos).
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_id' => ['nullable', 'regex:/^[0-9]{1,20}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'payment_id.regex' => 'El número de pago de Mercado Pago solo lleva dígitos.',
        ];
    }

    public function pagoMpId(): ?string
    {
        $valor = $this->validated('payment_id');

        return $valor === null || $valor === '' ? null : (string) $valor;
    }
}
