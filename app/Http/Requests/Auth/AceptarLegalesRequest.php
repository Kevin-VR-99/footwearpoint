<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AceptarLegalesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'documentos'               => ['required', 'array', 'min:1'],
            'documentos.*.tipo_documento' => [
                'required',
                Rule::in(['aviso_privacidad', 'terminos_condiciones']),
            ],
            'documentos.*.version'     => ['required', 'string', 'max:30'],
        ];
    }

    public function messages(): array
    {
        return [
            'documentos.required' => 'Debes enviar al menos un documento.',
            'documentos.*.tipo_documento.in' => 'Tipo de documento no válido.',
            'documentos.*.version.required' => 'La versión es obligatoria.',
        ];
    }
}