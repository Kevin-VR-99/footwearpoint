<?php

namespace App\Console\Commands;

use App\Models\ImportacionCatalogo;
use App\Models\ProductoImportadoStaging;
use App\Services\Catalogo\SubirCatalogoParaImportarAction;
use App\Services\Ia\MedirRecorte;
use App\Services\Ia\RasterizarPaginaPdf;
use App\Services\Ia\RecortarFotoDelCatalogo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * TG-377 (A17) — Medir cuánto se cortan los zapatos, antes y después.
 *
 * NO GASTA NADA DE API, y es la razón por la que esta tarjeta cabe en el
 * presupuesto. Los cuatro números del recuadro que devolvió la IA quedaron
 * guardados en datos_extraidos, y el PDF sigue en el bucket. Así que para
 * volver a recortar con otro margen basta con dibujar las páginas otra vez:
 * no hay que preguntarle nada al modelo ni pagar tokens de nuevo.
 *
 * Esto es lo que pide el «listo cuando» de la tarjeta: medición antes y
 * después sobre LA MISMA importación.
 *
 * Necesita pdftoppm (poppler), que en local no está y en Railway sí.
 */
class MedirRecortesDeCatalogo extends Command
{
    protected $signature = 'catalogo:medir-recortes
        {importacion : El id de la importación ya procesada}
        {--margenes=0,0.08 : Los márgenes a comparar, separados por coma}
        {--detalle : Enseña producto por producto, no solo el resumen}';

    protected $description = 'Mide cuánto se corta cada zapato con distintos márgenes, sin gastar API';

    public function __construct(
        private RasterizarPaginaPdf $rasterizador,
        private RecortarFotoDelCatalogo $recortador,
        private MedirRecorte $medidor,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->rasterizador->disponible()) {
            $this->error('Falta pdftoppm (poppler). En local no está: esto se corre en Railway.');

            return self::FAILURE;
        }

        $importacion = ImportacionCatalogo::find($this->argument('importacion'));

        if ($importacion === null) {
            $this->error('No existe esa importación.');

            return self::FAILURE;
        }

        $margenes = $this->margenes();
        $pdf = $this->pdfEnDisco($importacion);

        if ($pdf === null) {
            return self::FAILURE;
        }

        try {
            $filas = $this->medirTodo($importacion, $pdf, $margenes);
        } finally {
            @unlink($pdf);
        }

        if ($filas === []) {
            $this->warn('Esa importación no tiene productos con recuadro de foto.');

            return self::SUCCESS;
        }

        $this->informe($filas, $margenes);

        return self::SUCCESS;
    }

    /** @return list<float> */
    private function margenes(): array
    {
        $margenes = array_values(array_filter(
            array_map(
                fn (string $m): float => (float) trim($m),
                explode(',', (string) $this->option('margenes'))
            ),
            fn (float $m): bool => $m >= 0.0
        ));

        return $margenes === [] ? [0.0, 0.08] : $margenes;
    }

    private function pdfEnDisco(ImportacionCatalogo $importacion): ?string
    {
        $disco = Storage::disk(SubirCatalogoParaImportarAction::disco());

        if (! $disco->exists($importacion->archivo_url)) {
            $this->error('El PDF de esa importación ya no está en el bucket.');

            return null;
        }

        $ruta = tempnam(sys_get_temp_dir(), 'medir');

        if ($ruta === false || file_put_contents($ruta, (string) $disco->get($importacion->archivo_url)) === false) {
            $this->error('No se pudo dejar el PDF en disco.');

            return null;
        }

        return $ruta;
    }

    /**
     * Una fila por producto, con su medida para cada margen.
     *
     * Las páginas se dibujan UNA vez cada una aunque tengan varios productos:
     * dibujar es lo caro aquí (en tiempo, no en dinero).
     *
     * @param  list<float>  $margenes
     * @return list<array<string, mixed>>
     */
    private function medirTodo(ImportacionCatalogo $importacion, string $pdf, array $margenes): array
    {
        $porPagina = ProductoImportadoStaging::where('importacion_id', $importacion->id)
            ->get()
            ->groupBy(fn (ProductoImportadoStaging $p) => (int) ($p->datos_extraidos['pagina'] ?? 0))
            ->filter(fn ($productos, $pagina) => $pagina >= 1);

        $filas = [];
        $barra = $this->output->createProgressBar($porPagina->count());

        foreach ($porPagina as $pagina => $productos) {
            $imagen = $this->rasterizador->aPng($pdf, (int) $pagina);

            if ($imagen === null) {
                $barra->advance();

                continue;
            }

            try {
                $fondo = $this->medidor->fondoDeLaPagina($imagen);

                foreach ($productos as $producto) {
                    $fila = $this->medirUno($imagen, $producto, $margenes, $fondo);

                    if ($fila !== null) {
                        $filas[] = $fila;
                    }
                }
            } finally {
                @unlink($imagen);
            }

            $barra->advance();
        }

        $barra->finish();
        $this->newLine(2);

        return $filas;
    }

    /**
     * @param  list<float>  $margenes
     * @param  array{0: int, 1: int, 2: int}  $fondo
     * @return array<string, mixed>|null
     */
    private function medirUno(string $imagen, ProductoImportadoStaging $producto, array $margenes, array $fondo): ?array
    {
        $fila = ['producto' => $this->nombre($producto)];
        $hubo = false;

        foreach ($margenes as $margen) {
            $recorte = $this->recortador->recortar($imagen, $producto->datos_extraidos ?? [], $margen);

            if ($recorte === null) {
                $fila[(string) $margen] = null;

                continue;
            }

            $hubo = true;

            try {
                $fila[(string) $margen] = $this->medidor->porcentajeEnElBorde($recorte, $fondo);
            } finally {
                @unlink($recorte);
            }
        }

        // Sin recuadro usable con ningún margen: no es un recorte malo, es un
        // producto sin foto. Se cuenta aparte y no ensucia el promedio.
        return $hubo ? $fila : null;
    }

    private function nombre(ProductoImportadoStaging $producto): string
    {
        $datos = $producto->datos_extraidos ?? [];

        return trim(($datos['codigo_catalogo'] ?? '').' '.($datos['modelo'] ?? '')) ?: ('#'.$producto->id);
    }

    /**
     * @param  list<array<string, mixed>>  $filas
     * @param  list<float>  $margenes
     */
    private function informe(array $filas, array $margenes): void
    {
        if ($this->option('detalle')) {
            $this->table(
                array_merge(['Producto'], array_map(fn (float $m) => 'margen '.$m, $margenes)),
                array_map(fn (array $f) => array_map(
                    fn ($v) => $v === null ? 'sin recorte' : $v.'%',
                    array_values($f)
                ), $filas)
            );
        }

        $this->line('<options=bold>Cuánto queda del zapato tocando el borde del recorte</>');
        $this->line(sprintf('Productos medidos: %d', count($filas)));
        $this->newLine();

        $resumen = [];

        foreach ($margenes as $margen) {
            $valores = array_values(array_filter(
                array_column($filas, (string) $margen),
                fn ($v) => $v !== null
            ));

            $cortados = count(array_filter($valores, fn (float $v) => $v > 0.0));

            $resumen[] = [
                $margen,
                count($valores),
                $cortados,
                count($valores) > 0 ? round(100 * $cortados / count($valores)).'%' : '-',
                count($valores) > 0 ? round(array_sum($valores) / count($valores), 1).'%' : '-',
                $valores === [] ? '-' : max($valores).'%',
            ];
        }

        $this->table(
            ['Margen', 'Con recorte', 'Cortados', '% cortados', 'Borde promedio', 'El peor'],
            $resumen
        );

        $this->line('«Cortados» = su silueta toca el borde, o sea el zapato no cabe entero.');
    }
}
