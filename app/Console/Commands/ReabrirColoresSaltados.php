<?php

namespace App\Console\Commands;

use App\Models\ProductoImportadoStaging;
use Illuminate\Console\Command;

/**
 * php artisan catalogo:reabrir-colores-saltados [--confirmar]
 *
 * Rescata las filas que el aprobador viejo dejó en tierra de nadie.
 *
 * Hasta el PR #255, cuando el producto ya estaba en la temporada el
 * aprobador se salía marcando la fila como "aprobado" pero sin ligarla a
 * ninguna publicación. Casi siempre eso era el MISMO modelo en otro color
 * —los "DÚO" del Cklass repiten el código por color—, así que el color se
 * perdía: sus variantes quedaban sin disponibilidad y sin foto.
 *
 * Ese arreglo evita que vuelva a pasar, pero no rescata las que ya están
 * así: aprobar solo toma las filas en "corregido", y estas quedaron en
 * "aprobado". Las once del Cklass no las alcanza nadie, ni el botón ni
 * catalogo:publicar-fotos-ia, que pide producto_campana_creado_id.
 *
 * Esto las devuelve a "corregido" para que el botón las retome. No inventa
 * nada ni publica nada: solo reabre.
 */
class ReabrirColoresSaltados extends Command
{
    protected $signature = 'catalogo:reabrir-colores-saltados
                            {--confirmar : Reabre de verdad; sin esto solo cuenta}';

    protected $description = 'Devuelve a revisión las filas que el aprobador viejo saltó por ser otro color del mismo modelo';

    public function handle(): int
    {
        $confirmar = (bool) $this->option('confirmar');

        // La firma exacta de lo que dejó el código viejo: se dio por
        // aprobada, se supo a qué producto correspondía, pero nunca entró a
        // ninguna publicación. Una fila aprobada de verdad siempre tiene
        // producto_campana_creado_id.
        $consulta = ProductoImportadoStaging::query()
            ->withoutGlobalScopes()
            ->where('estado', 'aprobado')
            ->whereNotNull('producto_creado_id')
            ->whereNull('producto_campana_creado_id');

        $filas = $consulta->orderBy('id')->get();

        if ($filas->isEmpty()) {
            $this->info('No hay filas que reabrir.');

            return self::SUCCESS;
        }

        $this->table(
            ['Importación', 'Pág.', 'Código', 'Color'],
            $filas->map(fn (ProductoImportadoStaging $fila) => [
                $fila->importacion_id,
                $fila->datos_extraidos['pagina'] ?? '?',
                $fila->datos_extraidos['codigo_catalogo'] ?? '—',
                $fila->datos_extraidos['color'] ?? '—',
            ])->all(),
        );

        if (! $confirmar) {
            $this->info("Filas que se reabrirían: {$filas->count()}. Usa --confirmar para hacerlo.");
            $this->line('Después hay que entrar a cada importación y darle a «Aprobar los revisados».');

            return self::SUCCESS;
        }

        // Por importación, que es como se aprueban.
        $reabiertas = $consulta->update(['estado' => 'corregido']);

        $this->info("Filas reabiertas: {$reabiertas}.");
        $this->line('Ahora entra a esas importaciones y dale a «Aprobar los revisados»: '
            .'con el arreglo nuevo, el color se agrega a la publicación que ya existe.');

        return self::SUCCESS;
    }
}
