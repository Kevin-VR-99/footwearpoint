<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El código interno del cliente mayorista ahora lo pone el sistema (REV-001,
 * REV-002… por distribuidora). Los que ya tienen código lo conservan; a los
 * que no tienen se les asigna el siguiente libre. No se borra ni se cambia
 * ningún código existente.
 *
 * El índice único solo se agrega si no hay códigos repetidos dentro de una
 * misma distribuidora; si los hay, se deja como estaba para no tumbar el
 * despliegue (el sistema ya no genera repetidos).
 */
return new class extends Migration
{
    private const INDICE = 'revendedor_distribuidora_codigo_interno_unico';

    public function up(): void
    {
        $distribuidoras = DB::table('revendedor_distribuidora')
            ->whereNull('codigo_interno')
            ->distinct()
            ->pluck('distribuidora_id');

        foreach ($distribuidoras as $distribuidoraId) {
            DB::transaction(function () use ($distribuidoraId) {
                $mayor = DB::table('revendedor_distribuidora')
                    ->where('distribuidora_id', $distribuidoraId)
                    ->where('codigo_interno', 'like', 'REV-%')
                    ->lockForUpdate()
                    ->pluck('codigo_interno')
                    ->map(fn ($codigo) => preg_match('/^REV-(\d+)$/', (string) $codigo, $m) ? (int) $m[1] : 0)
                    ->max() ?? 0;

                $pendientes = DB::table('revendedor_distribuidora')
                    ->where('distribuidora_id', $distribuidoraId)
                    ->whereNull('codigo_interno')
                    ->orderBy('id')
                    ->pluck('id');

                foreach ($pendientes as $id) {
                    $mayor++;
                    DB::table('revendedor_distribuidora')
                        ->where('id', $id)
                        ->update(['codigo_interno' => 'REV-'.str_pad((string) $mayor, 3, '0', STR_PAD_LEFT)]);
                }
            });
        }

        $hayRepetidos = DB::table('revendedor_distribuidora')
            ->select('distribuidora_id', 'codigo_interno')
            ->whereNotNull('codigo_interno')
            ->groupBy('distribuidora_id', 'codigo_interno')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if (! $hayRepetidos && ! Schema::hasIndex('revendedor_distribuidora', self::INDICE)) {
            Schema::table('revendedor_distribuidora', function (Blueprint $tabla) {
                $tabla->unique(['distribuidora_id', 'codigo_interno'], self::INDICE);
            });
        }
    }

    public function down(): void
    {
        // Los códigos asignados se quedan: borrarlos perdería información.
        if (Schema::hasIndex('revendedor_distribuidora', self::INDICE)) {
            Schema::table('revendedor_distribuidora', function (Blueprint $tabla) {
                $tabla->dropUnique(self::INDICE);
            });
        }
    }
};
