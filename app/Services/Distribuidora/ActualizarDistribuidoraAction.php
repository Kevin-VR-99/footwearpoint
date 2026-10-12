<?php

namespace App\Services\Distribuidora;

use App\Models\Distribuidora;
use App\Rules\SubdominioValido;
use App\Services\Auditoria\AuditarCambios;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * TG-194 — El admin general corrige los datos de una distribuidora (los
 * mismos del formulario de alta).
 *
 * Slug y subdominio son únicos ignorando la propia; el subdominio pasa por
 * SubdominioValido y se guarda con CambiarSubdominioAction (que deja su propio
 * registro en la auditoría). El resto queda en la auditoría con el antes y el
 * después de lo que cambió (AuditarCambios, TG-267).
 */
class ActualizarDistribuidoraAction
{
    public const ACCION_AUDITORIA = 'distribuidora.datos_actualizados';

    private const CAMPOS_TEXTO = [
        'razon_social', 'descripcion_publica', 'direccion_publica',
        'telefono_publico', 'email_publico', 'horario_publico',
    ];

    public function __construct(
        private AuditarCambios $auditar,
        private CambiarSubdominioAction $cambiarSubdominio,
    ) {
    }

    /**
     * @param  array  $datos  nombre_comercial, slug y opcionales: razon_social, rfc,
     *                        subdominio, descripcion_publica, direccion_publica,
     *                        telefono_publico, email_publico, horario_publico.
     *
     * @throws ValidationException con los errores por campo (sin prefijo).
     */
    public function ejecutar(Distribuidora $distribuidora, array $datos): Distribuidora
    {
        $datos['nombre_comercial'] = trim((string) ($datos['nombre_comercial'] ?? ''));
        $datos['slug'] = trim((string) ($datos['slug'] ?? ''));
        $datos['rfc'] = CrearDistribuidoraAction::normalizarRfc($datos['rfc'] ?? null);
        // Igual que en el alta: sin subdominio, se usa el slug.
        $subdominio = strtolower(trim((string) ($datos['subdominio'] ?? '')));
        $datos['subdominio'] = $subdominio === '' ? strtolower($datos['slug']) : $subdominio;

        $id = (int) $distribuidora->id;

        Validator::make($datos, [
            'nombre_comercial'    => ['required', 'string', 'max:150'],
            'razon_social'        => ['nullable', 'string', 'max:200'],
            'rfc'                 => ['nullable', 'string', 'regex:/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/u', Rule::unique('distribuidoras', 'rfc')->ignore($id)],
            'slug'                => ['required', 'string', 'max:120', 'alpha_dash', Rule::unique('distribuidoras', 'slug')->ignore($id)],
            'subdominio'          => ['required', 'string', new SubdominioValido($id), Rule::unique('distribuidoras', 'subdominio')->ignore($id)],
            'email_publico'       => ['nullable', 'email', 'max:190'],
            'telefono_publico'    => ['nullable', 'string', 'max:30'],
            'direccion_publica'   => ['nullable', 'string', 'max:300'],
            'descripcion_publica' => ['nullable', 'string'],
            'horario_publico'     => ['nullable', 'string', 'max:300'],
        ], [
            'nombre_comercial.required' => 'El nombre comercial es obligatorio.',
            'nombre_comercial.max' => 'El nombre comercial no puede pasar de 150 caracteres.',
            'razon_social.max' => 'La razón social no puede pasar de 200 caracteres.',
            'rfc.regex' => 'El RFC no tiene un formato válido (12 caracteres persona moral o 13 persona física).',
            'rfc.unique' => 'Ese RFC ya está registrado en otra distribuidora.',
            'slug.required' => 'El slug es obligatorio.',
            'slug.max' => 'El slug no puede pasar de 120 caracteres.',
            'slug.unique' => 'Ese slug ya lo usa otra distribuidora.',
            'slug.alpha_dash' => 'El slug solo puede tener letras, números, guiones y guiones bajos.',
            'subdominio.required' => 'El subdominio es obligatorio.',
            'subdominio.unique' => 'Ese subdominio ya lo usa otra distribuidora.',
            'email_publico.email' => 'El email público no es válido.',
            'email_publico.max' => 'El email público no puede pasar de 190 caracteres.',
            'telefono_publico.max' => 'El teléfono público no puede pasar de 30 caracteres.',
            'direccion_publica.max' => 'La dirección pública no puede pasar de 300 caracteres.',
            'horario_publico.max' => 'El horario público no puede pasar de 300 caracteres.',
        ])->validate();

        $valores = [
            'nombre_comercial' => $datos['nombre_comercial'],
            'rfc'              => $datos['rfc'],
            'slug'             => $datos['slug'],
        ];
        foreach (self::CAMPOS_TEXTO as $campo) {
            $valores[$campo] = $this->textoONull($datos[$campo] ?? null);
        }

        // La auditoría queda ligada a la distribuidora editada (el admin
        // general no tiene una propia).
        return Tenant::forzar($id, fn () => DB::transaction(function () use ($distribuidora, $valores, $datos) {
            $distribuidora->fill($valores);
            $this->auditar->guardar($distribuidora, self::ACCION_AUDITORIA, 'distribuidora');

            $this->cambiarSubdominio->ejecutar($distribuidora, $datos['subdominio']);

            return $distribuidora->refresh();
        }));
    }

    private function textoONull(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }
}
