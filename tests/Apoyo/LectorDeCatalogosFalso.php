<?php

namespace Tests\Apoyo;

use Anthropic\Core\Exceptions\BadRequestException;
use App\Services\Ia\BloqueDeCatalogo;
use App\Services\Ia\LectorDeCatalogos;
use App\Services\Ia\UsoDeTokens;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
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

    /** Si se pone, un rango de más páginas que esto "no cabe" (max_tokens). */
    public ?int $cortaSiAbarcaMasDe = null;

    /** Para probar que un borrado fallido no deja el identificador puesto. */
    public bool $borrarTruena = false;

    /** Si se pone, el bloque truena con el 400 de "sin saldo" de Anthropic. */
    public bool $sinSaldo = false;

    /** Si se pone, el bloque truena con un 400 que NO es de saldo. */
    public bool $otroErrorDelProveedor = false;

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

        if ($this->sinSaldo) {
            throw self::cuatrocientos(
                'Your credit balance is too low to access the Anthropic API. Please go to Plans & Billing to upgrade or purchase credits.'
            );
        }

        if ($this->otroErrorDelProveedor) {
            throw self::cuatrocientos('max_tokens: 99999 > 64000, which is the maximum allowed.');
        }

        if ($this->fallaEnElBloqueQueEmpiezaEn === $desde) {
            throw new RuntimeException('La IA respondió algo que no se pudo leer en las páginas '.$desde.' a '.$hasta.'.');
        }

        if ($this->cortaSiAbarcaMasDe !== null && ($hasta - $desde + 1) > $this->cortaSiAbarcaMasDe) {
            throw new \App\Services\Ia\RespuestaCortadaPorTokens($desde, $hasta, new UsoDeTokens(entrada: 341, salida: 16000));
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

    /**
     * Un 400 del proveedor, armado como lo arma el SDK: el mensaje de la
     * excepción es el JSON del cuerpo, y de ahí sale el texto que se revisa.
     */
    public static function cuatrocientos(string $mensaje): BadRequestException
    {
        return new BadRequestException(
            request: new Request('POST', 'https://api.anthropic.com/v1/messages'),
            response: new Response(400, ['content-type' => 'application/json'], (string) json_encode([
                'type'  => 'error',
                'error' => ['type' => 'invalid_request_error', 'message' => $mensaje],
            ])),
        );
    }

    public function borrar(string $archivoIa): void
    {
        if ($this->borrarTruena) {
            throw new RuntimeException('El proveedor no dejó borrar el archivo.');
        }

        $this->borrado = true;
    }
}
