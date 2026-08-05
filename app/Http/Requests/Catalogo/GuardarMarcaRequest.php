<?php

namespace App\Http\Requests\Catalogo;

use Illuminate\Foundation\Http\FormRequest;

class GuardarMarcaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin_distribuidora') ?? false;
    }

    public function rules(): array
    {
        $requerido = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'nombre'      => [$requerido, 'string', 'max:120'],
            'descripcion' => ['nullable', 'string'],
            'activa'      => ['sometimes', 'boolean'],
            // Igual que en el logo de perfil (Bloque 1): ningún documento
            // especifica un tamaño máximo para el logo de marca. 2MB, mismo
            // criterio conservador que usé allá.
            'logotipo'    => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ];
    }
}
