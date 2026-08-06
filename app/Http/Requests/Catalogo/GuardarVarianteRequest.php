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
                // productos SÍ es de tu tenant, se acota a mano igual que antes.
                Rule::exists('productos', 'id')->where(fn ($q) => $q->where('distribuidora_id', Tenant::id())),
            ];

            // tallas y colores son catálogos GLOBALES (sin distribuidora_id,
            // sembrados en Fase 0) — aquí SÍ se usa el "exists" corto normal,
            // porque no hay ningún tenant que acotar: cualquier distribuidora
            // puede usar cualquier talla/color del catálogo compartido.
            $reglas['talla_id'] = ['required', 'integer', 'exists:tallas,id'];
            $reglas['color_id'] = ['required', 'integer', 'exists:colores,id'];
        }
        // producto_id/talla_id/color_id NO se aceptan en edición: cambiar la
        // combinación de una variante ya creada rompería su sku (decisión
        // provisional mía) — si la combinación está mal, se borra y se crea
        // de nuevo.

        return $reglas;
    }
}
