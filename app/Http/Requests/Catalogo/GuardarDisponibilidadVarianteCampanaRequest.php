<?php

namespace App\Http\Requests\Catalogo;

use App\Support\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarDisponibilidadVarianteCampanaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin_distribuidora') ?? false;
    }

    public function rules(): array
    {
        $esCreacion = $this->isMethod('POST');

        $reglas = [
            // "estado" es el único campo editable de verdad — por eso es
            // "required" también en edición, no "sometimes" (no habría
            // nada más que mandar en un PATCH).
            'estado' => ['required', Rule::in(['disponible', 'bajo_pedido', 'no_disponible'])],
        ];

        if ($esCreacion) {
            $reglas['producto_campana_id'] = [
                'required',
                'integer',
                Rule::exists('producto_campana', 'id')->where(fn ($q) => $q->where('distribuidora_id', Tenant::id())),
            ];
            $reglas['variante_id'] = [
                'required',
                'integer',
                Rule::exists('variantes', 'id')->where(fn ($q) => $q->where('distribuidora_id', Tenant::id())),
            ];
        }
        // producto_campana_id/variante_id no se aceptan en edición — el par
        // ya queda fijo desde que se crea (decisión provisional mía).

        return $reglas;
    }
}
