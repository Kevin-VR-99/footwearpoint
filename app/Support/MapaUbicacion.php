<?php

namespace App\Support;

use App\Models\Distribuidora;
use App\Models\Sucursal;

/**
 * TG-269 (U9, E15-02) - Mapa con la ubicación de la distribuidora.
 *
 * No hay coordenadas guardadas: el mapa se busca por la dirección en Google
 * Maps (inserción sin llave). La dirección es la pública de la distribuidora
 * o, si no tiene, la de su sucursal principal.
 */
final class MapaUbicacion
{
    /** La dirección a mostrar y buscar en el mapa, o null si no hay. */
    public static function direccion(Distribuidora $distribuidora): ?string
    {
        $publica = trim((string) $distribuidora->direccion_publica);

        if ($publica !== '') {
            return $publica;
        }

        if (! $distribuidora->getKey()) {
            return null;
        }

        // La tienda pública no tiene distribuidora en sesión: se consulta sin el filtro por distribuidora.
        $sucursal = trim((string) Sucursal::withoutGlobalScopes()
            ->where('distribuidora_id', $distribuidora->getKey())
            ->where('es_principal', true)
            ->where('activa', true)
            ->value('direccion'));

        return $sucursal !== '' ? $sucursal : null;
    }

    /** El texto que se busca: la dirección con ", México" si no lo trae. */
    public static function consulta(?string $direccion): ?string
    {
        $direccion = trim((string) $direccion);

        if ($direccion === '') {
            return null;
        }

        return preg_match('/m[eé]xico/iu', $direccion) ? $direccion : $direccion.', México';
    }

    /** Dirección del mapa para insertar en la página (iframe). */
    public static function urlInsertar(Distribuidora $distribuidora): ?string
    {
        return self::url('https://maps.google.com/maps', ['q' => self::consultaDe($distribuidora), 'output' => 'embed']);
    }

    /** "Cómo llegar": abre las indicaciones hasta la distribuidora. */
    public static function urlComoLlegar(Distribuidora $distribuidora): ?string
    {
        return self::url('https://www.google.com/maps/dir/', ['api' => 1, 'destination' => self::consultaDe($distribuidora)]);
    }

    /** "Ver en mapa": abre la búsqueda de la dirección. */
    public static function urlVerEnMapa(Distribuidora $distribuidora): ?string
    {
        return self::url('https://www.google.com/maps/search/', ['api' => 1, 'query' => self::consultaDe($distribuidora)]);
    }

    private static function consultaDe(Distribuidora $distribuidora): ?string
    {
        return self::consulta(self::direccion($distribuidora));
    }

    private static function url(string $base, array $parametros): ?string
    {
        if (in_array(null, $parametros, true)) {
            return null;
        }

        return $base.'?'.http_build_query($parametros, '', '&', PHP_QUERY_RFC3986);
    }
}
