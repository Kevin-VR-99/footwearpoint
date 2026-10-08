<?php

namespace App\Http\Requests\Catalogo;

use App\Support\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarCampanaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin_general') ?? false;
    }

    public function rules(): array
    {
        $esCreacion = $this->isMethod('POST');

        $reglas = [
            'nombre'       => [$esCreacion ? 'required' : 'sometimes', 'string', 'max:150'],
            'descripcion'  => ['nullable', 'string'],
            'fecha_inicio' => ['nullable', 'date'],
            // CHECK chk_campana_fechas: fecha_fin >= fecha_inicio.
            'fecha_fin'    => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
        ];

        // La temporada pertenece a UNA línea (D1, TG-209). Se elige al crearla
        // y no cambia después: mover una temporada de línea se llevaría con
        // ella todo su catálogo.
        if ($esCreacion) {
            $reglas['linea_id'] = [
                'required',
                'integer',
                Rule::exists('lineas', 'id'),
            ];
        }

        // "estado" NO se valida aquí contra el enum completo: la Action
        // valida que solo avance a el SIGUIENTE estado de la secuencia,
        // no cualquier valor del enum.
        if (! $esCreacion) {
            $reglas['estado'] = ['sometimes', 'string'];
        }

        return $reglas;
    }
}
