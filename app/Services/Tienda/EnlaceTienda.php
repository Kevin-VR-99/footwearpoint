<?php

namespace App\Services\Tienda;

use App\Models\Distribuidora;

/**
 * TG-234 (G15) — La dirección de la tienda pública de una distribuidora
 * (TG-233), para enlazarla desde el marketplace (web y API) y desde la
 * propia tienda.
 *
 * Es el único lugar donde se arma. TG-232 (G12/G13): con
 * FOOTWEARPOINT_DOMINIO configurado es https://{subdominio}.{dominio}/ (el
 * subdominio de la distribuidora o, si no tiene uno válido, su slug); sin él,
 * /tienda/{slug} como antes.
 *
 * Solo las distribuidoras activas tienen tienda (TiendaPublica responde 404
 * a las demás), así que para cualquier otra devuelve null y no se muestra
 * el enlace.
 */
class EnlaceTienda
{
    public function url(Distribuidora $distribuidora): ?string
    {
        if (! $this->tieneTienda($distribuidora)) {
            return null;
        }

        $raiz = $this->raizSubdominio($distribuidora);

        return $raiz !== null ? $raiz.'/' : route('tienda', (string) $distribuidora->slug);
    }

    /** La ficha de un producto de esa tienda. */
    public function producto(Distribuidora $distribuidora, int $productoCampanaId): ?string
    {
        if (! $this->tieneTienda($distribuidora)) {
            return null;
        }

        $raiz = $this->raizSubdominio($distribuidora);

        return $raiz !== null
            ? $raiz.'/productos/'.$productoCampanaId
            : route('tienda.producto', [(string) $distribuidora->slug, $productoCampanaId]);
    }

    /** El directorio de distribuidoras, siempre en el dominio principal. */
    public function marketplace(): string
    {
        return DominioTienda::principal().'/marketplace';
    }

    /** El subdominio con el que se abre la tienda, o null si no puede tener uno. */
    public function subdominio(Distribuidora $distribuidora): ?string
    {
        foreach ([$distribuidora->subdominio, $distribuidora->slug] as $candidato) {
            $candidato = is_string($candidato) ? strtolower(trim($candidato)) : null;

            if (DominioTienda::etiquetaValida($candidato)) {
                return $candidato;
            }
        }

        return null;
    }

    private function tieneTienda(Distribuidora $distribuidora): bool
    {
        return $distribuidora->estado === 'activa'
            && preg_match('/^[a-z0-9-]{1,120}$/', (string) $distribuidora->slug) === 1;
    }

    private function raizSubdominio(Distribuidora $distribuidora): ?string
    {
        $base = DominioTienda::base();
        $subdominio = $base !== null ? $this->subdominio($distribuidora) : null;

        return $subdominio !== null ? DominioTienda::esquema().'://'.$subdominio.'.'.$base : null;
    }
}
