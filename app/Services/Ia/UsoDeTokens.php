<?php

namespace App\Services\Ia;

/**
 * TG-238 (A8) — Lo que consumió una petición a la IA.
 *
 * Se lleva aparte el caché porque es la mitad del diseño: el catálogo entero
 * se manda UNA vez y queda guardado; de ahí en adelante cada bloque lo vuelve
 * a leer a una fracción del precio. Medido con el catálogo Confort: la
 * primera petición escribe 119,653 tokens y las siguientes los leen del
 * caché, que cuesta cuarenta veces menos.
 */
class UsoDeTokens
{
    public function __construct(
        public int $entrada = 0,
        public int $salida = 0,
        public int $cacheEscritura = 0,
        public int $cacheLectura = 0,
    ) {
    }

    public function mas(self $otro): self
    {
        return new self(
            $this->entrada + $otro->entrada,
            $this->salida + $otro->salida,
            $this->cacheEscritura + $otro->cacheEscritura,
            $this->cacheLectura + $otro->cacheLectura,
        );
    }

    /** Todo lo que entró, venga de donde venga. Es lo que se guarda en la tabla. */
    public function totalDeEntrada(): int
    {
        return $this->entrada + $this->cacheEscritura + $this->cacheLectura;
    }

    /** Dólares, con los precios de config/ia.php. */
    public function costo(): float
    {
        $p = config('ia.precios');

        return round(
            $this->entrada * $p['entrada'] / 1_000_000
            + $this->salida * $p['salida'] / 1_000_000
            + $this->cacheEscritura * $p['cache_escritura'] / 1_000_000
            + $this->cacheLectura * $p['cache_lectura'] / 1_000_000,
            4
        );
    }
}
