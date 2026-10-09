<?php

namespace App\Services\Tienda;

use App\Models\Distribuidora;

/**
 * TG-234 (G15) — La dirección de la tienda pública de una distribuidora
 * (TG-233), para enlazarla desde el marketplace (web y API).
 *
 * Es el único lugar donde se arma: cuando cada distribuidora tenga su
 * subdominio (G12/G13), solo se cambia aquí.
 *
 * Solo las distribuidoras activas tienen tienda (TiendaPublica responde 404
 * a las demás), así que para cualquier otra devuelve null y no se muestra
 * el enlace.
 */
class EnlaceTienda
{
    public function url(Distribuidora $distribuidora): ?string
    {
        $slug = (string) $distribuidora->slug;

        if ($distribuidora->estado !== 'activa' || preg_match('/^[a-z0-9-]{1,120}$/', $slug) !== 1) {
            return null;
        }

        return route('tienda', $slug);
    }
}
