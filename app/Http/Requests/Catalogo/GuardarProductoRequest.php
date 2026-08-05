<?php

namespace App\Http\Requests\Catalogo;

use App\Support\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarProductoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin_distribuidora') ?? false;
    }

    public function rules(): array
    {
        $esCreacion = $this->isMethod('POST');
        $requerido = $esCreacion ? 'required' : 'sometimes';

        $reglas = [
            'modelo'      => [$requerido, 'string', 'max:120'],
            'nombre'      => [$requerido, 'string', 'max:200'],
            'descripcion' => ['nullable', 'string'],
            'activo'      => ['sometimes', 'boolean'],
            'categoria_id' => [
                $requerido,
                'integer',
                // Igual que en campañas: acotado al tenant a mano, la regla
                // "exists" normal no respeta el Global Scope.
                Rule::exists('categorias_producto', 'id')->where(fn ($q) => $q->where('distribuidora_id', Tenant::id())),
            ],
        ];

        if ($esCreacion) {
            // marca_id solo se acepta al crear — no se cambia después
            // (decisión provisional mía, ver LEEME).
            $reglas['marca_id'] = [
                'required',
                'integer',
                Rule::exists('marcas', 'id')->where(fn ($q) => $q->where('distribuidora_id', Tenant::id())),
            ];
        }

        return $reglas;
    }
}
