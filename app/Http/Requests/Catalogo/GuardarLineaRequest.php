<?php

namespace App\Http\Requests\Catalogo;

use App\Support\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarLineaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin_general') ?? false;
    }

    public function rules(): array
    {
        $esCreacion = $this->isMethod('POST');
        $requerido = $esCreacion ? 'required' : 'sometimes';

        return [
            // La línea ya no cuelga de una temporada: es al revés (D1).
            'nombre' => [$requerido, 'string', 'max:150'],
            'descripcion' => ['nullable', 'string'],
            'logotipo_url' => ['nullable', 'string', 'max:500'],
            'activa' => ['sometimes', 'boolean'],
            'marca_ids' => ['sometimes', 'array'],
            'marca_ids.*' => [
                'integer',
                Rule::exists('marcas', 'id'),
            ],
        ];
    }
}
