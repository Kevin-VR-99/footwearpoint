<?php

namespace App\Http\Requests\Distribuidora;

use Illuminate\Foundation\Http\FormRequest;

class GuardarConfiguracionCicloRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin_distribuidora') ?? false;
    }

    public function rules(): array
    {
        // POST (crear) exige todos los campos; PATCH (actualizar) los vuelve opcionales.
        $requerido = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            // CHECK chk_dias_semana_ciclo: dia_cierre y dia_solicitud_fabrica entre 1 y 7.
            'dia_cierre'             => [$requerido, 'integer', 'between:1,7'],
            'hora_cierre'            => [$requerido, 'date_format:H:i'],
            'dia_solicitud_fabrica'  => [$requerido, 'integer', 'between:1,7'],
            'dias_estimados_llegada' => [$requerido, 'integer', 'min:1'],
            'activa'                 => ['sometimes', 'boolean'],
            // CHECK chk_dia_recepcion: cada día entre 1 y 7.
            'dias_recepcion'         => [$requerido, 'array', 'min:1'],
            'dias_recepcion.*'       => ['integer', 'between:1,7'],
        ];
    }
}
