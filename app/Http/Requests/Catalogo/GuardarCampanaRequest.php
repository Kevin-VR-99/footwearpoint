<?php

namespace App\Http\Requests\Catalogo;

use App\Support\Tenant;
use App\Services\Catalogo\GestionarCampanaAction;
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
            'descripcion'  => ['nullable', 'string', 'max:2000'],
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

        // Aquí solo se cierra a los valores del enum (auditoría 21-oct-2026);
        // la Action sigue validando que solo avance al SIGUIENTE estado.
        if (! $esCreacion) {
            $reglas['estado'] = ['sometimes', 'string', Rule::in(GestionarCampanaAction::ORDEN_ESTADOS)];
        }

        return $reglas;
    }

    public function messages(): array
    {
        return [
            'estado.in' => 'El estado de la temporada no es válido.',
            'descripcion.max' => 'La descripción no puede tener más de 2000 caracteres.',
        ];
    }
}
