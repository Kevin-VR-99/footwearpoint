<?php

namespace App\Console\Commands;

use App\Models\ProductoCampana;
use App\Models\ProductoImportadoStaging;
use App\Services\Ia\AprobarProductosImportadosAction;
use Illuminate\Console\Command;

/**
 * php artisan catalogo:publicar-fotos-ia [--galeria] [--confirmar]
 *
 * A los productos que ya entraron por la importación con IA y siguen sin
 * foto les pone el recorte que la IA sacó del catálogo. No toca las fotos que
 * ya tengan ni las que el revisor descartó. Se puede correr otra vez.
 *
 * Con --galeria hace lo otro: el segundo color de un modelo comparte
 * publicación con el primero, y esa publicación ya tiene foto, así que el
 * modo normal lo salta. Aquí su recorte entra como una foto más de la
 * galería (orden siguiente, nunca como principal). La galería es por
 * publicación, no por color.
 */
class PublicarFotosIa extends Command
{
    protected $signature = 'catalogo:publicar-fotos-ia
        {--galeria : Agrega a la galería el recorte de las filas cuya publicación ya tiene foto}
        {--confirmar : Publica de verdad; sin esto solo cuenta}';

    protected $description = 'Pone el recorte de la IA a los productos importados que no tienen foto';

    public function handle(AprobarProductosImportadosAction $aprobar): int
    {
        return $this->option('galeria')
            ? $this->galeria($aprobar, (bool) $this->option('confirmar'))
            : $this->sinFoto($aprobar, (bool) $this->option('confirmar'));
    }

    private function filasConRecorte()
    {
        return ProductoImportadoStaging::query()
            ->withoutGlobalScopes()
            ->whereNotNull('producto_campana_creado_id')
            ->whereNotNull('foto_propuesta_url')
            ->whereIn('foto_estado', ['propuesta', 'confirmada'])
            ->orderBy('id');
    }

    private function sinFoto(AprobarProductosImportadosAction $aprobar, bool $confirmar): int
    {
        $candidatos = 0;
        $publicadas = 0;

        $this->filasConRecorte()
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

    /**
     * Qué filas entran: su publicación ya tiene foto, no son la primera fila
     * de esa publicación (esa foto ya salió de la primera, por el modo
     * normal o al aprobar) y no tienen marca de haberse agregado ya.
     */
    private function galeria(AprobarProductosImportadosAction $aprobar, bool $confirmar): int
    {
        $candidatos = 0;
        $agregadas = 0;

        $this->filasConRecorte()
            ->whereNull('producto_imagen_galeria_id')
            ->each(function (ProductoImportadoStaging $fila) use ($aprobar, $confirmar, &$candidatos, &$agregadas) {
                $pc = ProductoCampana::query()->withoutGlobalScopes()->find($fila->producto_campana_creado_id);

                if ($pc === null || ! $pc->imagenes()->exists()) {
                    return;
                }

                $primera = ProductoImportadoStaging::query()
                    ->withoutGlobalScopes()
                    ->where('producto_campana_creado_id', $pc->id)
                    ->min('id');

                if ((int) $primera === (int) $fila->id) {
                    return;
                }

                $candidatos++;

                if (! $confirmar) {
                    return;
                }

                $imagen = $aprobar->agregarFotoAGaleria($fila, $pc);

                if ($imagen !== null) {
                    $fila->update(['producto_imagen_galeria_id' => $imagen->id]);
                    $agregadas++;
                }
            });

        $this->info($confirmar
            ? "Fotos agregadas a la galería: {$agregadas} de {$candidatos}."
            : "Recortes de otro color que se agregarían a la galería: {$candidatos}. Usa --confirmar para agregarlos.");

        return self::SUCCESS;
    }
}