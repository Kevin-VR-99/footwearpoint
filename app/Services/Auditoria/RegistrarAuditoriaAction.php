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

    /**
     * TG-377 — La IP de quien PIDIÓ el trabajo, cuando no hay petición propia.
     *
     * POR QUÉ EXISTE. Aprobar un catálogo se fue a la cola, y el worker no
     * tiene petición: `request()->ip()` ahí devuelve `127.0.0.1`, que parece
     * un dato real y no lo es. El 27-nov-2026 las 300+ filas de aprobar el
     * catálogo Impuls quedaron con esa IP falsa, mientras que los inicios de
     * sesión del mismo día guardaron la de verdad (84.17.44.227). El QUIÉN
     * estaba bien; el DESDE DÓNDE mentía.
     *
     * Son dos variables y no una porque hay que distinguir «nadie la fijó»
     * de «se fijó, y es nula»: un trabajo encolado desde la consola no tiene
     * IP de origen, y null es la respuesta honesta, no `127.0.0.1`.
     */
    private static bool $hayIpDeQuienPidio = false;

    private static ?string $ipDeQuienPidio = null;

    /**
     * Corre $hacer anotando que el origen fue esa IP.
     *
     * Es de ámbito (y no un "poner/olvidar" suelto) para que el valor no se
     * pueda quedar pegado: el finally lo restaura aunque $hacer truene.
     */
    public static function conIpDeOrigen(?string $ip, callable $hacer): mixed
    {
        $habia = self::$hayIpDeQuienPidio;
        $antes = self::$ipDeQuienPidio;

        self::$hayIpDeQuienPidio = true;
        self::$ipDeQuienPidio = $ip;

        try {
            return $hacer();
        } finally {
            self::$hayIpDeQuienPidio = $habia;
            self::$ipDeQuienPidio = $antes;
        }
    }

    private static function ipDeOrigen(): ?string
    {
        if (self::$hayIpDeQuienPidio) {
            return self::$ipDeQuienPidio;
        }

        return request()?->ip();
    }

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
            'ip_origen'        => self::ipDeOrigen(),
        ]);
    }

    /**
     * TG-337: para lo que es de la plataforma, aunque quien abre el enlace
     * tenga sesión en una distribuidora. Sin eventos, porque BelongsToTenant
     * completaría la distribuidora al crear.
     */
    public function sinDistribuidora(
        string $accion,
        string $entidadTipo,
        ?int $entidadId = null,
        ?array $datosPrevios = null,
        ?array $datosNuevos = null
    ): Auditoria {
        return Auditoria::withoutEvents(fn () => Auditoria::create([
            'usuario_id'       => Auth::id(),
            'distribuidora_id' => null,
            'accion'           => $accion,
            'entidad_tipo'     => $entidadTipo,
            'entidad_id'       => $entidadId,
            'datos_previos'    => self::limpiar($datosPrevios),
            'datos_nuevos'     => self::limpiar($datosNuevos),
            'ip_origen'        => self::ipDeOrigen(),
        ]));
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
