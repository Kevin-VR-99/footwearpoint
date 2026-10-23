<?php

namespace App\Http\Requests\Catalogo;

use App\Services\Catalogo\LogotipoDeCatalogo;
use Illuminate\Foundation\Http\FormRequest;

class GuardarMarcaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin_general') ?? false;
    }

    public function rules(): array
    {
        $requerido = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'nombre'      => [$requerido, 'string', 'max:120'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'activa'      => ['sometimes', 'boolean'],
            // 2MB, mismo criterio que el logo de perfil. JPG, PNG, WebP o SVG
            // (el SVG con código se rechaza: ver SvgSeguro).
            'logotipo'    => LogotipoDeCatalogo::reglas(),
            'quitar_logotipo' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return LogotipoDeCatalogo::mensajes('logotipo') + [
            'descripcion.max' => 'La descripción no puede tener más de 2000 caracteres.',
        ];
    }
}
