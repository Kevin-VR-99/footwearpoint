<?php

namespace App\Http\Requests\Distribuidora;

use Illuminate\Foundation\Http\FormRequest;

class ActualizarConfiguracionDistribuidoraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin_distribuidora') ?? false;
    }

    /**
     * Reglas numéricas alineadas EXACTO con la regla CHECK real del modelo v3
     * (chk_config_valores: anticipo_por_producto >= 0 AND los 4 "dias_*" > 0).
     *
     * mercado_pago_account_id NO se acepta aquí a propósito: aunque vive en la
     * misma tabla, es E3-04 y está fuera de alcance este sprint.
     */
    public function rules(): array
    {
        return [
            'anticipo_por_producto'    => ['sometimes', 'required', 'numeric', 'min:0'],
            'dias_solicitud_cambio'    => ['sometimes', 'required', 'integer', 'min:1'],
            'dias_gestion_devolucion'  => ['sometimes', 'required', 'integer', 'min:1'],
            'dias_vigencia_vale'       => ['sometimes', 'required', 'integer', 'min:1'],
            'dias_maximos_recoleccion' => ['sometimes', 'required', 'integer', 'min:1'],
            'moneda'                   => ['sometimes', 'required', 'string', 'size:3'],
            'zona_horaria'             => ['sometimes', 'required', 'string', 'max:60'],
        ];
    }
}
