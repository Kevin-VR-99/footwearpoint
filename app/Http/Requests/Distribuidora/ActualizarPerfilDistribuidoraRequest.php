<?php

namespace App\Http\Requests\Distribuidora;

use App\Models\Distribuidora;
use App\Support\Tenant;
use App\Support\WhatsApp;
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
     * - subdominio: E3-02 (TG-232) se edita desde el perfil del panel web con
     *   CambiarSubdominioAction, no por esta API.
     * - marketplace_visible: la controla admin_general vía Paquete A (E2-05),
     *   no el propio administrador de la distribuidora.
     */
    public function rules(): array
    {
        return [
            'nombre_comercial'    => ['sometimes', 'required', 'string', 'max:150'],
            'descripcion_publica' => ['nullable', 'string', 'max:2000'],
            'direccion_publica'   => ['nullable', 'string', 'max:300'],
            'telefono_publico'    => ['nullable', 'string', 'max:30'],
            'telefono_es_whatsapp' => ['sometimes', 'boolean'],
            'email_publico'       => ['nullable', 'email', 'max:190'],
            'horario_publico'     => ['nullable', 'string', 'max:300'],
            // Nota: ningún mockup ni documento especifica un tamaño máximo para el
            // logo (sí lo hacen para imágenes de producto: 5MB). 2MB es un valor
            // conservador propio, ajústalo si el equipo define otro.
            'logotipo'            => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'descripcion_publica.max' => 'La descripción pública no puede tener más de 2000 caracteres.',
            'telefono_es_whatsapp' => 'Ese número no se puede usar en WhatsApp. Escríbelo con 10 dígitos o déjalo solo para llamadas.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (! $this->exists('telefono_es_whatsapp') || ! $this->boolean('telefono_es_whatsapp')) {
                return;
            }

            $telefono = $this->exists('telefono_publico')
                ? $this->input('telefono_publico')
                : Distribuidora::query()->whereKey(Tenant::id())->value('telefono_publico');

            if (WhatsApp::normalizarTelefono(is_string($telefono) ? $telefono : null) === null) {
                $validator->errors()->add(
                    'telefono_es_whatsapp',
                    'Ese número no se puede usar en WhatsApp. Escríbelo con 10 dígitos o déjalo solo para llamadas.',
                );
            }
        });
    }
}
