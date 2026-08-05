<?php

namespace App\Http\Requests\Catalogo;

use App\Support\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarProductoCampanaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin_distribuidora') ?? false;
    }

    public function rules(): array
    {
        $esCreacion = $this->isMethod('POST');
        $requerido = $esCreacion ? 'required' : 'sometimes';

        $reglas = [
            'codigo_catalogo'           => [$requerido, 'string', 'max:120'],
            // CHECK chk_precios_producto_campana: ambos >= 0.
            'precio_mayorista'          => [$requerido, 'numeric', 'min:0'],
            'precio_minorista_sugerido' => [$requerido, 'numeric', 'min:0'],
            'estado_disponibilidad'     => ['sometimes', Rule::in(['disponible', 'bajo_pedido', 'no_disponible'])],
            'publicado'                 => ['sometimes', 'boolean'],
        ];

        if ($esCreacion) {
            // producto_id y campana_id solo se aceptan al crear — no se
            // cambia de producto ni de campaña después (decisión
            // provisional mía, mismo criterio que en Bloque 3b).
            $reglas['producto_id'] = [
                'required',
                'integer',
                Rule::exists('productos', 'id')->where(fn ($q) => $q->where('distribuidora_id', Tenant::id())),
            ];
            $reglas['campana_id'] = [
                'required',
                'integer',
                Rule::exists('campanas', 'id')->where(fn ($q) => $q->where('distribuidora_id', Tenant::id())),
            ];
        }

        return $reglas;
    }
}
