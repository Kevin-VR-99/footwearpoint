<?php

namespace App\Http\Requests\Catalogo;

use App\Support\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarVarianteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin_distribuidora') ?? false;
    }

    public function rules(): array
    {
        $esCreacion = $this->isMethod('POST');

        $reglas = [
            'nombre_color_comercial' => ['nullable', 'string', 'max:100'],
            'activa'                 => ['sometimes', 'boolean'],
        ];

        if ($esCreacion) {
            $reglas['producto_id'] = [
                'required',
                'integer',
                Rule::exists('productos', 'id')->where(fn ($q) => $q->where('distribuidora_id', Tenant::id())),
            ];

            $reglas['talla_id'] = ['required', 'integer', 'exists:tallas,id'];

            // CORRECCIÓN: antes esto solo dependía de la regla CHECK de la
            // base de datos (uq_variante_combinacion), que sí bloqueaba el
            // duplicado pero con un error 500 crudo, no un 422 limpio como
            // pide la sección 1.7. Ahora se valida aquí explícitamente.
            $reglas['color_id'] = [
                'required',
                'integer',
                'exists:colores,id',
                Rule::unique('variantes')->where(function ($query) {
                    return $query->where('producto_id', $this->input('producto_id'))
                        ->where('talla_id', $this->input('talla_id'))
                        ->where('distribuidora_id', Tenant::id());
                }),
            ];
        }

        return $reglas;
    }

    public function messages(): array
    {
        return [
            'color_id.unique' => 'Ya existe una variante con esa combinación de talla y color para este producto.',
        ];
    }
}
