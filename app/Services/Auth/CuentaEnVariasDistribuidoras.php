<?php

namespace App\Services\Auth;

use App\Models\Usuario;
use Illuminate\Support\Facades\DB;

/**
 * ¿La cuenta de este cliente mayorista o cliente la usa mas de una
 * distribuidora?
 *
 * Hallazgo de seguridad sobre TG-193 (lo reporto Gaby en la revision del
 * repo). Una misma persona puede estar dada de alta en varias
 * distribuidoras, pero su CUENTA es una sola: revendedor_distribuidora liga
 * al mismo revendedor con N distribuidoras, y clientes_directos puede tener
 * al mismo usuario_id en varias.
 *
 * Con la contrasena temporal, el admin de la distribuidora A le cambiaba la
 * contrasena a esa cuenta unica, se la dictaba, y con eso podia entrar como
 * esa persona y ver lo suyo con la distribuidora B. El enlace por correo
 * (TG-192) no tiene ese problema, porque le llega al dueno de la cuenta.
 *
 * Solo cuentan los vinculos ACTIVOS: una afiliacion suspendida o inactiva ya
 * no deja ver nada de esa distribuidora.
 */
class CuentaEnVariasDistribuidoras
{
    public const MENSAJE = 'Esta cuenta también la usa otra distribuidora, así que desde aquí no se le puede poner una contraseña temporal: se la estarías cambiando también allá. Usa «Enviar enlace», que solo le llega a la persona.';

    /**
     * Los ids de las distribuidoras donde esta cuenta tiene un vinculo
     * activo, sin repetir. Se pregunta con DB::table y sin los scopes de
     * tenant a proposito: justamente hay que ver MAS ALLA de la
     * distribuidora actual, pero sin cargar ni mostrar un solo dato ajeno.
     *
     * @return array<int, int>
     */
    public function distribuidorasDe(Usuario $cuenta): array
    {
        $comoRevendedor = DB::table('revendedor_distribuidora as rd')
            ->join('revendedores as r', 'r.id', '=', 'rd.revendedor_id')
            ->where('r.usuario_id', $cuenta->id)
            ->where('rd.estado', 'activo')
            ->pluck('rd.distribuidora_id');

        $comoCliente = DB::table('clientes_directos')
            ->where('usuario_id', $cuenta->id)
            ->where('estado', 'activo')
            ->pluck('distribuidora_id');

        return $comoRevendedor->merge($comoCliente)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** True si la cuenta la comparten dos o mas distribuidoras. */
    public function estaCompartida(Usuario $cuenta): bool
    {
        return count($this->distribuidorasDe($cuenta)) > 1;
    }
}
