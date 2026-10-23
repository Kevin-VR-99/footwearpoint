<?php

namespace App\Support;

/**
 * Oculta los secretos que viajan en la dirección (enlace para restablecer la
 * contraseña, hash y firma del enlace para confirmar el correo, tokens en la
 * query) antes de escribirlos en un log. El resto de la ruta se queda igual
 * para que el log siga sirviendo.
 */
class OcultarTokensEnUrl
{
    public const OCULTO = '[oculto]';

    /** @var array<string, string> */
    private const PATRONES = [
        '#(/reset-password/)[^\s/?\#"\']+#i' => '$1'.self::OCULTO,
        '#(/verificar-correo/\d+/)[^\s/?\#"\']+#i' => '$1'.self::OCULTO,
        '#([?&](?:signature|token|hash|code)=)[^\s&\#"\']+#i' => '$1'.self::OCULTO,
    ];

    public static function texto(string $texto): string
    {
        return (string) preg_replace(array_keys(self::PATRONES), array_values(self::PATRONES), $texto);
    }

    /**
     * @param  array<mixed>  $datos
     * @return array<mixed>
     */
    public static function arreglo(array $datos): array
    {
        array_walk_recursive($datos, function (&$valor) {
            if (is_string($valor)) {
                $valor = self::texto($valor);
            }
        });

        return $datos;
    }
}
