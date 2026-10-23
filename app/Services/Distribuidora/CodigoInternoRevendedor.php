<?php

namespace App\Services\Distribuidora;

use Illuminate\Support\Facades\DB;

/**
 * Código interno del cliente mayorista: REV-001, REV-002… consecutivo dentro
 * de cada distribuidora (la misma forma que usa DemoContactosSeeder).
 *
 * Se toma el número más alto con forma REV-### y se le suma uno; los códigos
 * viejos escritos a mano con otra forma no estorban. Se bloquean los renglones
 * de la distribuidora para que dos altas al mismo tiempo no saquen el mismo.
 */
class CodigoInternoRevendedor
{
    public const PREFIJO = 'REV-';

    public function siguiente(int $distribuidoraId): string
    {
        $codigos = DB::table('revendedor_distribuidora')
            ->where('distribuidora_id', $distribuidoraId)
            ->where('codigo_interno', 'like', self::PREFIJO.'%')
            ->lockForUpdate()
            ->pluck('codigo_interno');

        $mayor = $codigos
            ->map(fn ($codigo) => preg_match('/^'.preg_quote(self::PREFIJO, '/').'(\d+)$/', (string) $codigo, $m) ? (int) $m[1] : 0)
            ->max() ?? 0;

        return self::PREFIJO.str_pad((string) ($mayor + 1), 3, '0', STR_PAD_LEFT);
    }
}
