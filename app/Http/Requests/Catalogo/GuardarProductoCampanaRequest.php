<?php

namespace App\Http\Requests\Catalogo;

use App\Support\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarProductoCampanaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin_general') ?? false;
    }

    public function rules(): array
    {
        $esCreacion = $this->isMethod('POST');
        $requerido = $esCreacion ? 'required' : 'sometimes';

        $reglas = [
            'codigo_catalogo' => [$requerido, 'string', 'max:120'],
            // Un solo precio, el de menudeo del catálogo (D8). El mayoreo lo
            // pone cada distribuidora en su propia capa.
            'precio_publico'  => [$requerido, 'numeric', 'min:0'],
            // El admin general puede retirar un producto de la temporada.
            'activo'          => ['sometimes', 'boolean'],
        ];

        if ($esCreacion) {
            // producto_id y campana_id solo se aceptan al crear — no se
            // cambia de producto ni de campaña después (decisión
            // provisional mía, mismo criterio que en Bloque 3b).
            $reglas['producto_id'] = [
                'required',
                'integer',
                Rule::exists('productos', 'id'),
            ];
            $reglas['campana_id'] = [
                'required',
                'integer',
                Rule::exists('campanas', 'id'),
            ];
        }

        return $reglas;
    }
}
