<?php

namespace App\Services\Tienda;

/**
 * TG-232 (G12/G13) — Todo lo que hay que saber del dominio de las tiendas:
 * cuál es el dominio base (FOOTWEARPOINT_DOMINIO), qué nombres de subdominio
 * son válidos y si una petición llegó por el subdominio de una tienda.
 *
 * Sin dominio base configurado, nada de esto se usa y la tienda sigue en
 * /tienda/{slug}.
 */
class DominioTienda
{
    /** Una etiqueta DNS: minúsculas, números y guiones; 3 a 63; sin guion al inicio ni al final. */
    public const FORMATO = '/^[a-z0-9](?:[a-z0-9-]{1,61}[a-z0-9])$/';

    public static function base(): ?string
    {
        $base = config('app.dominio_base');

        return is_string($base) && $base !== '' ? $base : null;
    }

    public static function configurado(): bool
    {
        return self::base() !== null;
    }

    public static function esReservado(string $etiqueta): bool
    {
        return in_array(strtolower($etiqueta), (array) config('app.subdominios_reservados', []), true);
    }

    /** ¿Se puede usar como subdominio de tienda? (formato DNS y no reservado). */
    public static function etiquetaValida(?string $etiqueta): bool
    {
        return is_string($etiqueta)
            && preg_match(self::FORMATO, $etiqueta) === 1
            && ! self::esReservado($etiqueta);
    }

    /**
     * Si el host es {algo}.{dominio base} (un solo nivel), regresa ese "algo"
     * tal cual llegó (sin validar). Si no, null.
     */
    public static function etiquetaDelHost(string $host): ?string
    {
        $base = self::base();
        $host = strtolower(rtrim($host, '.'));

        if ($base === null || ! str_ends_with($host, '.'.$base)) {
            return null;
        }

        $etiqueta = substr($host, 0, -strlen('.'.$base));

        return $etiqueta !== '' && ! str_contains($etiqueta, '.') ? $etiqueta : null;
    }

    /** Esquema con el que se arman las direcciones de las tiendas (el de APP_URL). */
    public static function esquema(): string
    {
        return str_starts_with((string) config('app.url'), 'http://') ? 'http' : 'https';
    }

    /**
     * Patrones de host aceptados (TrustHosts). Vacío = se acepta cualquiera,
     * que es lo que pasa sin dominio base configurado.
     *
     * @return list<string>
     */
    public static function hostsDeConfianza(): array
    {
        $base = self::base();

        if ($base === null) {
            return [];
        }

        $hosts = ['^(.+\\.)?'.preg_quote($base).'$', '^healthcheck\\.railway\\.app$'];
        $principal = parse_url(self::principal(), PHP_URL_HOST);

        if (is_string($principal) && $principal !== '') {
            $hosts[] = '^'.preg_quote($principal).'$';
        }

        return $hosts;
    }

    /** Dirección del dominio principal (APP_URL), sin la diagonal final. */
    public static function principal(): string
    {
        return rtrim((string) config('app.url'), '/');
    }
}
