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
                Rule::exists('categorias_producto', 'id')->where(
                    fn($q) => $q->where('distribuidora_id', Tenant::id())
                ),
            ],
            'linea_id' => [
                $requerido,
                'integer',
                Rule::exists('lineas', 'id')->where(
                    fn($q) => $q->where('distribuidora_id', Tenant::id())
                ),
            ],
            'marca_id' => [
                $requerido,
                'integer',
                Rule::exists('marcas', 'id')->where(
                    fn($q) => $q->where('distribuidora_id', Tenant::id())
                ),
            ],
        ];

        return $reglas;
    }
}
