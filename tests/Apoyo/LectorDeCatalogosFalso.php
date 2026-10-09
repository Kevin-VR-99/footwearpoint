<?php

namespace Tests\Apoyo;

use App\Services\Ia\BloqueDeCatalogo;
use App\Services\Ia\LectorDeCatalogos;
use App\Services\Ia\UsoDeTokens;
use RuntimeException;

/**
 * TG-238 (A8) — Un Claude de mentiras para las pruebas.
 *
 * Las pruebas nunca deben llamar al API de verdad: cuesta dinero, necesita la
 * llave y tardaría minutos. Este lector se comporta igual (sube, lee bloques,
 * borra) y además deja ver qué se le pidió.
 */
class LectorDeCatalogosFalso implements LectorDeCatalogos
{
    /** @var array<int, array{desde: int, hasta: int}> */
    public array $bloquesPedidos = [];

    public ?string $archivoSubido = null;

    public bool $borrado = false;

    public int $vecesQueSubio = 0;

    /** Si se pone, el bloque que empiece en esa página truena. */
    public ?int $fallaEnElBloqueQueEmpiezaEn = null;

    /** @param array<int, array<string, mixed>> $productosPorPagina */
    public function __construct(private array $productosPorPagina = [])
    {
    }

    public function subir(string $contenido, string $nombre): string
    {
        $this->vecesQueSubio++;
        $this->archivoSubido = $nombre;

        return 'file_de_prueba_123';
    }

    public function leerBloque(string $archivoIa, int $desde, int $hasta): BloqueDeCatalogo
    {
        $this->bloquesPedidos[] = ['desde' => $desde, 'hasta' => $hasta];

        if ($this->fallaEnElBloqueQueEmpiezaEn === $desde) {
            throw new RuntimeException('La IA respondió algo que no se pudo leer en las páginas '.$desde.' a '.$hasta.'.');
        }

        $productos = [];

        foreach ($this->productosPorPagina as $pagina => $producto) {
            if ($pagina >= $desde && $pagina <= $hasta) {
                $productos[] = $producto + ['pagina' => $pagina];
            }
        }

        // Imita lo medido con el catálogo real: el primer bloque escribe el
        // documento en el caché y los demás lo leen.
        $primero = count($this->bloquesPedidos) === 1;

        return new BloqueDeCatalogo(
            productos: $productos,
            uso: new UsoDeTokens(
                entrada: 341,
                salida: 4000,
                cacheEscritura: $primero ? 119653 : 0,
                cacheLectura: $primero ? 0 : 119653,
            ),
        );
    }

    public function borrar(string $archivoIa): void
    {
        $this->borrado = true;
    }
}
