<?php

namespace App\Http\Requests\Catalogo;

use App\Services\Catalogo\LogotipoDeCatalogo;
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
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'logotipo_url' => ['nullable', 'string', 'max:500'],
            // Igual que en marcas: JPG, PNG, WebP o SVG seguro, hasta 2MB.
            'logotipo' => LogotipoDeCatalogo::reglas(),
            'quitar_logotipo' => ['sometimes', 'boolean'],
            'activa' => ['sometimes', 'boolean'],
            'marca_ids' => ['sometimes', 'array'],
            'marca_ids.*' => [
                'integer',
                Rule::exists('marcas', 'id'),
            ],
        ];
    }

    public function messages(): array
    {
        return LogotipoDeCatalogo::mensajes('logotipo') + [
            'descripcion.max' => 'La descripción no puede tener más de 2000 caracteres.',
        ];
    }
}
