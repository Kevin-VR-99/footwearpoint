<?php

namespace App\Services\Correo;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * ¿El dominio de un correo existe de verdad? Lo damos por bueno si tiene un
 * registro MX y, si no, un A o AAAA (RFC 5321: sin MX se entrega al A).
 *
 * El resultado se guarda poco tiempo en caché para no consultar el DNS en
 * cada intento. No falla "abierto": si el dominio no resuelve, se rechaza.
 *
 * Las pruebas no deben tocar el DNS real: el resolvedor se inyecta y
 * tests/TestCase.php registra uno falso (ver VerificadorDominioCorreo::falso).
 */
class VerificadorDominioCorreo
{
    private const SEGUNDOS_CACHE_EXISTE = 600;

    private const SEGUNDOS_CACHE_NO_EXISTE = 60;

    /** @var Closure(string): bool */
    private Closure $resolvedor;

    public function __construct(?Closure $resolvedor = null)
    {
        $this->resolvedor = $resolvedor ?? static fn (string $dominio): bool => self::consultarDns($dominio);
    }

    /**
     * Resolvedor para pruebas: todo dominio existe salvo los de la lista y
     * los que terminan en .invalid (reservado por RFC 2606).
     *
     * @param  array<int, string>  $inexistentes
     */
    public static function falso(array $inexistentes = []): self
    {
        $inexistentes = array_map('strtolower', $inexistentes);

        return new self(static fn (string $dominio): bool => ! in_array($dominio, $inexistentes, true)
            && ! str_ends_with($dominio, '.invalid'));
    }

    public function existe(string $dominio): bool
    {
        $dominio = self::normalizar($dominio);

        if ($dominio === '' || ! str_contains($dominio, '.')) {
            return false;
        }

        $clave = 'correo-dominio:'.$dominio;
        $guardado = Cache::get($clave);

        if (is_bool($guardado)) {
            return $guardado;
        }

        $existe = (bool) ($this->resolvedor)($dominio);

        Cache::put($clave, $existe, $existe ? self::SEGUNDOS_CACHE_EXISTE : self::SEGUNDOS_CACHE_NO_EXISTE);

        return $existe;
    }

    public static function normalizar(string $dominio): string
    {
        $dominio = rtrim(strtolower(trim($dominio)), '.');

        if ($dominio !== '' && function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $dominio) === 1) {
            $ascii = idn_to_ascii($dominio, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            $dominio = $ascii === false ? '' : $ascii;
        }

        return $dominio;
    }

    private static function consultarDns(string $dominio): bool
    {
        // El punto final evita que el resolvedor le pegue el dominio de búsqueda local.
        $fqdn = $dominio.'.';

        return checkdnsrr($fqdn, 'MX') || checkdnsrr($fqdn, 'A') || checkdnsrr($fqdn, 'AAAA');
    }
}
