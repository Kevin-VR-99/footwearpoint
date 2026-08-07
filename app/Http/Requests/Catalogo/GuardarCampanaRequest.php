<?php

namespace App\Http\Requests\Catalogo;

use App\Support\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarCampanaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin_distribuidora') ?? false;
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

        // marca_id es legado y nullable: la campaña ya no se dueña por marca
        // (relación real = Campaña → Líneas).
        if ($esCreacion) {
            $reglas['marca_id'] = [
                'nullable',
                'integer',
                Rule::exists('marcas', 'id')->where(fn($q) => $q->where('distribuidora_id', Tenant::id())),
            ];
        }
        // marca_id NO se acepta en edición: una campaña no cambia de marca
        // después de creada (decisión provisional mía, ver LEEME).

        // "estado" NO se valida aquí contra el enum completo: la Action
        // valida que solo avance a el SIGUIENTE estado de la secuencia,
        // no cualquier valor del enum.
        if (! $esCreacion) {
            $reglas['estado'] = ['sometimes', 'string'];
        }

        return $reglas;
    }
}
