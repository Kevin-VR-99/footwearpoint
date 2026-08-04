<?php

namespace App\Http\Requests\Distribuidora;

use Illuminate\Foundation\Http\FormRequest;

class ActualizarPerfilDistribuidoraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin_distribuidora') ?? false;
    }

    /**
     * Solo los campos de E3-01 (datos comerciales). NO incluye:
     * - razon_social, rfc, slug: no forman parte del criterio de aceptación de E3-01.
     * - subdominio: E3-02, fuera de alcance este sprint.
     * - marketplace_visible: la controla admin_general vía Paquete A (E2-05),
     *   no el propio administrador de la distribuidora.
     */
    public function rules(): array
    {
        return [
            'nombre_comercial'    => ['sometimes', 'required', 'string', 'max:150'],
            'descripcion_publica' => ['nullable', 'string'],
            'direccion_publica'   => ['nullable', 'string', 'max:300'],
            'telefono_publico'    => ['nullable', 'string', 'max:30'],
            'email_publico'       => ['nullable', 'email', 'max:190'],
            'horario_publico'     => ['nullable', 'string', 'max:300'],
            // Nota: ningún mockup ni documento especifica un tamaño máximo para el
            // logo (sí lo hacen para imágenes de producto: 5MB). 2MB es un valor
            // conservador propio, ajústalo si el equipo define otro.
            'logotipo'            => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ];
    }
}
