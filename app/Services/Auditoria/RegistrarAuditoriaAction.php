<?php

namespace App\Services\Auditoria;

use App\Models\Auditoria;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;

class RegistrarAuditoriaAction
{
    /**
     * TG-334 (K36): nunca se guardan contraseñas, tokens ni credenciales,
     * aunque una acción los mande por error. Se comparan sin mayúsculas.
     */
    public const CAMPOS_PROHIBIDOS = [
        'password',
        'password_confirmation',
        'password_actual',
        'admin_password',
        'admin_password_confirmation',
        'remember_token',
        'token',
        'access_token',
        'refresh_token',
        'mp_access_token',
        'mp_refresh_token',
        'mp_public_key',
        'api_key',
        'secret',
        'client_secret',
    ];

    /** Datos bancarios: solo se guardan los últimos 4 dígitos. */
    public const CAMPOS_ENMASCARADOS = ['clabe'];

    public function ejecutar(
        string $accion,
        string $entidadTipo,
        ?int $entidadId = null,
        ?array $datosPrevios = null,
        ?array $datosNuevos = null
    ): Auditoria {
        return Auditoria::create([
            'usuario_id'       => Auth::id(),
            'distribuidora_id' => Tenant::id(),
            'accion'           => $accion,
            'entidad_tipo'     => $entidadTipo,
            'entidad_id'       => $entidadId,
            'datos_previos'    => self::limpiar($datosPrevios),
            'datos_nuevos'     => self::limpiar($datosNuevos),
            'ip_origen'        => request()?->ip(),
        ]);
    }

    /** Quita lo prohibido y enmascara la CLABE, también dentro de arreglos. */
    public static function limpiar(?array $datos): ?array
    {
        if ($datos === null) {
            return null;
        }

        $limpio = [];

        foreach ($datos as $clave => $valor) {
            $nombre = is_string($clave) ? mb_strtolower($clave) : null;

            if ($nombre !== null && in_array($nombre, self::CAMPOS_PROHIBIDOS, true)) {
                continue;
            }

            if ($nombre !== null && in_array($nombre, self::CAMPOS_ENMASCARADOS, true) && $valor !== null) {
                $limpio[$clave] = self::enmascarar((string) $valor);

                continue;
            }

            $limpio[$clave] = is_array($valor) ? self::limpiar($valor) : $valor;
        }

        return $limpio;
    }

    /** «012180001234567897» -> «••••••••••••••7897». */
    public static function enmascarar(string $valor): string
    {
        $valor = trim($valor);
        $largo = mb_strlen($valor);

        if ($largo <= 4) {
            return str_repeat('•', $largo);
        }

        return str_repeat('•', $largo - 4).mb_substr($valor, -4);
    }
}
