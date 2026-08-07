<?php

namespace App\Http\Requests\Catalogo;

use App\Support\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarLineaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin_distribuidora') ?? false;
    }

    public function rules(): array
    {
        $esCreacion = $this->isMethod('POST');
        $requerido = $esCreacion ? 'required' : 'sometimes';

        return [
            'campana_id' => [
                $esCreacion ? 'required' : 'prohibited',
                'integer',
                Rule::exists('campanas', 'id')->where(
                    fn ($q) => $q->where('distribuidora_id', Tenant::id())
                ),
            ],
            'nombre' => [$requerido, 'string', 'max:150'],
            'descripcion' => ['nullable', 'string'],
            'activa' => ['sometimes', 'boolean'],
            'marca_ids' => ['sometimes', 'array'],
            'marca_ids.*' => [
                'integer',
                Rule::exists('marcas', 'id')->where(
                    fn ($q) => $q->where('distribuidora_id', Tenant::id())
                ),
            ],
        ];
    }
}