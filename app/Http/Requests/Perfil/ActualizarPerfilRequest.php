<?php

namespace App\Http\Requests\Perfil;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/perfil (E1-05 / TG-110).
 *
 * Solo nombre y teléfono: el correo es la llave de acceso y no se cambia
 * desde aquí. Los máximos son los de las columnas de usuarios, revendedores y
 * clientes_directos (las tres miden igual), porque se guardan en las tres.
 */
class ActualizarPerfilRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Un teléfono vacío o con puros espacios se guarda como "sin teléfono".
        if ($this->has('telefono')) {
            $telefono = trim((string) $this->input('telefono'));

            $this->merge(['telefono' => $telefono === '' ? null : $telefono]);
        }

        if ($this->has('nombre')) {
            $this->merge(['nombre' => trim((string) $this->input('nombre'))]);
        }
    }

    public function rules(): array
    {
        return [
            'nombre'   => ['required', 'string', 'max:150'],
            'telefono' => ['nullable', 'string', 'max:30'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre es obligatorio.',
            'nombre.max'      => 'El nombre no puede tener más de 150 caracteres.',
            'telefono.max'    => 'El teléfono no puede tener más de 30 caracteres.',
        ];
    }
}
