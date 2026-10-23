<?php

namespace App\Console\Commands;

use App\Models\ProductoCampana;
use App\Models\ProductoImportadoStaging;
use App\Services\Ia\AprobarProductosImportadosAction;
use Illuminate\Console\Command;

/**
 * php artisan catalogo:publicar-fotos-ia [--confirmar]
 *
 * A los productos que ya entraron por la importación con IA y siguen sin
 * foto les pone el recorte que la IA sacó del catálogo. No toca las fotos que
 * ya tengan ni las que el revisor descartó. Se puede correr otra vez.
 */
class PublicarFotosIa extends Command
{
    protected $signature = 'catalogo:publicar-fotos-ia {--confirmar : Publica de verdad; sin esto solo cuenta}';

    protected $description = 'Pone el recorte de la IA a los productos importados que no tienen foto';

    public function handle(AprobarProductosImportadosAction $aprobar): int
    {
        $confirmar = (bool) $this->option('confirmar');
        $candidatos = 0;
        $publicadas = 0;

        ProductoImportadoStaging::query()
            ->withoutGlobalScopes()
            ->whereNotNull('producto_campana_creado_id')
            ->whereNotNull('foto_propuesta_url')
            ->whereIn('foto_estado', ['propuesta', 'confirmada'])
            ->orderBy('id')
            ->each(function (ProductoImportadoStaging $fila) use ($aprobar, $confirmar, &$candidatos, &$publicadas) {
                $pc = ProductoCampana::query()->withoutGlobalScopes()->find($fila->producto_campana_creado_id);

                if ($pc === null || $pc->imagenes()->exists()) {
                    return;
                }

                $candidatos++;

                if ($confirmar) {
                    $aprobar->publicarFoto($fila, $pc);
                    $publicadas += $pc->imagenes()->exists() ? 1 : 0;
                }
            });

        $this->info($confirmar
            ? "Fotos publicadas: {$publicadas} de {$candidatos}."
            : "Productos importados sin foto que tienen recorte de la IA: {$candidatos}. Usa --confirmar para publicarlas.");

        return self::SUCCESS;
    }
}