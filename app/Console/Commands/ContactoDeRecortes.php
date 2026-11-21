<?php

namespace App\Console\Commands;

use App\Models\ImportacionCatalogo;
use App\Models\ProductoImportadoStaging;
use App\Services\Ia\PaginaDelCatalogo;
use App\Services\Ia\RecortarFotoDelCatalogo;
use Illuminate\Console\Command;

/**
 * TG-377 (A17) — Hojas de contacto para decidir el recorte MIRANDO.
 *
 * POR QUÉ ESTO Y NO UN PORCENTAJE. El primer intento de esta tarjeta fue
 * medir con un número: cuánta silueta del zapato toca el borde del recorte,
 * contando píxeles que no fueran del color del fondo. Ese número mintió dos
 * veces:
 *
 *  - Sacaba el fondo de las esquinas de la página, y estas páginas traen DOS
 *    fondos (una mitad gris, otra blanca). El promedio no era ninguno, así
 *    que TODOS los recortes salían «cortados» con cualquier margen.
 *  - Corregido eso, seguía contando la frontera gris/blanca como si fuera el
 *    zapato, y daba un «óptimo» que al mirarlo no lo era.
 *
 * Al abrir las imágenes se vio en diez segundos lo que el número escondió: en
 * unos productos el recuadro corta el zapato y en otros ya se tragó el
 * letrero del precio. Ningún margen global arregla los dos.
 *
 * Así que la medición de esta tarjeta son imágenes para mirar, no una cifra.
 * Genera una hoja por producto con el mismo zapato recortado a varios
 * márgenes, apiladas y rotuladas.
 *
 * No gasta API: los recuadros de la IA ya están guardados y el PDF sigue en
 * el bucket. Solo hace falta pdftoppm (poppler), que en local no está.
 */
class ContactoDeRecortes extends Command
{
    protected $signature = 'catalogo:contacto-recortes
        {importacion : El id de la importación ya procesada}
        {--pagina= : Solo los productos de esa página}
        {--productos=6 : Cuántos productos sacar}
        {--margenes=0,0.03,0.10,0.15,0.20 : Los márgenes a comparar}
        {--ancho=560 : Ancho de cada recorte en la hoja}
        {--destino= : Carpeta donde dejarlas (por defecto storage/app/recortes)}';

    protected $description = 'Genera hojas de contacto del mismo zapato a varios márgenes, para elegir mirando';

    public function __construct(
        private PaginaDelCatalogo $paginas,
        private RecortarFotoDelCatalogo $recortador,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $importacion = ImportacionCatalogo::find($this->argument('importacion'));

        if ($importacion === null) {
            $this->error('No existe esa importación.');

            return self::FAILURE;
        }

        $margenes = $this->margenes();
        $destino = $this->destino();

        if (! is_dir($destino) && ! @mkdir($destino, 0775, true)) {
            $this->error('No se pudo crear '.$destino);

            return self::FAILURE;
        }

        $productos = $this->productos($importacion);

        if ($productos === []) {
            $this->warn('Esa importación no tiene productos con recuadro de foto.');

            return self::SUCCESS;
        }

        $hechas = 0;

        foreach ($productos as $producto) {
            $pagina = (int) ($producto->datos_extraidos['pagina'] ?? 0);
            $png = $this->paginas->enDiscoLocal($importacion, $pagina);

            if ($png === null) {
                $this->warn('No se pudo dibujar la página '.$pagina.'. ¿Está pdftoppm (poppler)?');

                continue;
            }

            try {
                $archivo = $this->hoja($png, $producto, $margenes, $destino);
            } finally {
                @unlink($png);
            }

            if ($archivo !== null) {
                $this->line('  '.$archivo);
                $hechas++;
            }
        }

        $this->newLine();
        $this->info($hechas.' hoja(s) en '.$destino);
        $this->line('Ábrelas y mira en qué margen el zapato cabe entero SIN que entre el letrero.');
        $this->line('Si no hay un margen que sirva para todos, es que los recuadros de la IA son');
        $this->line('inconsistentes: esos se arreglan a mano desde «Ajustar recorte».');

        return self::SUCCESS;
    }

    /** @return list<float> */
    private function margenes(): array
    {
        $margenes = array_values(array_filter(
            array_map(fn (string $m): float => (float) trim($m), explode(',', (string) $this->option('margenes'))),
            fn (float $m): bool => $m >= 0.0
        ));

        return $margenes === [] ? [0.0, 0.03] : $margenes;
    }

    private function destino(): string
    {
        $destino = (string) ($this->option('destino') ?: storage_path('app/recortes'));

        return rtrim($destino, '/\\');
    }

    /** @return list<ProductoImportadoStaging> */
    private function productos(ImportacionCatalogo $importacion): array
    {
        $consulta = ProductoImportadoStaging::where('importacion_id', $importacion->id);

        $todos = $consulta->get()
            ->filter(fn (ProductoImportadoStaging $p) => (int) ($p->datos_extraidos['pagina'] ?? 0) >= 1)
            ->filter(fn (ProductoImportadoStaging $p) => $this->recortador->cajaFinal($p->datos_extraidos ?? []) !== null);

        if ($this->option('pagina')) {
            $pagina = (int) $this->option('pagina');
            $todos = $todos->filter(fn (ProductoImportadoStaging $p) => (int) ($p->datos_extraidos['pagina'] ?? 0) === $pagina);
        }

        return $todos->take(max(1, (int) $this->option('productos')))->values()->all();
    }

    /**
     * Una hoja: el mismo zapato recortado a cada margen, uno debajo de otro.
     *
     * Entre recorte y recorte va una franja de color chillón y el rótulo del
     * margen. El color sirve: deja ver exactamente dónde acaba cada recorte,
     * que es justo lo que hay que juzgar.
     *
     * @param  list<float>  $margenes
     */
    private function hoja(string $rutaPagina, ProductoImportadoStaging $producto, array $margenes, string $destino): ?string
    {
        $pagina = @imagecreatefrompng($rutaPagina);

        if ($pagina === false) {
            return null;
        }

        try {
            $ancho = max(200, (int) $this->option('ancho'));
            $trozos = [];

            foreach ($margenes as $margen) {
                $caja = $this->recortador->cajaFinal($producto->datos_extraidos ?? [], $margen);

                if ($caja === null) {
                    continue;
                }

                $recorte = $this->recorte($pagina, $caja);

                if ($recorte !== null) {
                    $trozos[] = [$margen, imagescale($recorte, $ancho)];
                    imagedestroy($recorte);
                }
            }

            if ($trozos === []) {
                return null;
            }

            return $this->pegar($trozos, $ancho, $destino, $this->nombre($producto));
        } finally {
            imagedestroy($pagina);
        }
    }

    /** @param array{0: float, 1: float, 2: float, 3: float} $caja */
    private function recorte(\GdImage $pagina, array $caja): ?\GdImage
    {
        [$x, $y, $w, $h] = $caja;
        $anchoPagina = imagesx($pagina);
        $altoPagina = imagesy($pagina);

        $recorte = imagecrop($pagina, [
            'x' => (int) round($x * $anchoPagina),
            'y' => (int) round($y * $altoPagina),
            'width' => (int) round($w * $anchoPagina),
            'height' => (int) round($h * $altoPagina),
        ]);

        return $recorte === false ? null : $recorte;
    }

    /**
     * @param  list<array{0: float, 1: \GdImage}>  $trozos
     */
    private function pegar(array $trozos, int $ancho, string $destino, string $nombre): ?string
    {
        $franja = 26;
        $alto = 0;

        foreach ($trozos as [, $imagen]) {
            $alto += imagesy($imagen) + $franja;
        }

        $hoja = imagecreatetruecolor($ancho, $alto);
        // Magenta: no existe en una foto de zapato, así que se ve de lejos
        // dónde acaba un recorte y empieza el siguiente.
        imagefill($hoja, 0, 0, imagecolorallocate($hoja, 255, 0, 255));
        $blanco = imagecolorallocate($hoja, 255, 255, 255);
        $y = 0;

        foreach ($trozos as [$margen, $imagen]) {
            imagestring($hoja, 5, 6, $y + 6, 'margen '.$margen, $blanco);
            $y += $franja;
            imagecopy($hoja, $imagen, 0, $y, 0, 0, imagesx($imagen), imagesy($imagen));
            $y += imagesy($imagen);
            imagedestroy($imagen);
        }

        $archivo = $destino.'/'.$nombre.'.jpg';
        $ok = imagejpeg($hoja, $archivo, 74);
        imagedestroy($hoja);

        return $ok ? $archivo : null;
    }

    private function nombre(ProductoImportadoStaging $producto): string
    {
        $datos = $producto->datos_extraidos ?? [];
        $base = trim((string) ($datos['codigo_catalogo'] ?? '')) ?: ('producto-'.$producto->id);

        return 'pag'.((int) ($datos['pagina'] ?? 0)).'-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', $base);
    }
}
