<?php

namespace App\Services\Ia;

use Anthropic\Client;
use Anthropic\Core\FileParam;
use RuntimeException;

/**
 * TG-238 (A8) — El único sitio que habla con Claude.
 *
 * Cómo funciona, y por qué así:
 *
 *  - El catálogo se sube UNA vez con el Files API. Los catálogos reales pesan
 *    unos 29 MB y el tope de una petición son 32 MB; metido dentro de la
 *    petición se convierte a texto y crece un tercio, así que no cabría.
 *    Subirlo, listarlo y borrarlo es gratis: solo se paga lo que se lee.
 *
 *  - No se parte el PDF. Partirlo en PHP no es posible sin pagar una librería
 *    o instalar un programa aparte en el servidor (el catálogo de Impuls usa
 *    flujos comprimidos, que la librería libre no sabe leer). En vez de eso,
 *    cada petición manda el MISMO archivo y le pide un rango de páginas.
 *
 *  - Para que eso no salga caro, el documento va marcado para guardarse en
 *    caché una hora. Medido con el catálogo Confort (76 páginas, 119,653
 *    tokens): la primera petición lo escribe y cuesta cerca de un dólar; de
 *    ahí en adelante cada bloque lo lee del caché por dos centavos y medio.
 *
 *  - La respuesta viene con formato obligado (JSON con esquema), así que no
 *    hay que adivinar ni limpiar texto.
 */
class LectorDeCatalogosClaude implements LectorDeCatalogos
{
    public function __construct(private Client $claude)
    {
    }

    public function subir(string $contenido, string $nombre): string
    {
        $archivo = $this->claude->files->upload(
            file: FileParam::fromString($contenido, $nombre, 'application/pdf'),
            expiresInSeconds: max(3600, (int) config('ia.horas_del_archivo') * 3600),
        );

        return $archivo->id;
    }

    public function leerBloque(string $archivoIa, int $desde, int $hasta): BloqueDeCatalogo
    {
        $respuesta = $this->claude->messages->create(
            model: (string) config('ia.modelo'),
            maxTokens: (int) config('ia.max_tokens_por_bloque'),
            outputConfig: ['format' => ['type' => 'json_schema', 'schema' => self::esquema()]],
            messages: [[
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'document',
                        'source' => ['type' => 'file', 'fileID' => $archivoIa],
                        // Que se quede guardado una hora: lo vuelven a leer
                        // todos los bloques siguientes.
                        'cacheControl' => ['type' => 'ephemeral', 'ttl' => '1h'],
                    ],
                    ['type' => 'text', 'text' => self::instruccion($desde, $hasta)],
                ],
            ]],
        );

        $texto = '';
        foreach ($respuesta->content as $bloque) {
            if ($bloque->type === 'text') {
                $texto .= $bloque->text;
            }
        }

        $datos = json_decode($texto, true);

        if (! is_array($datos) || ! isset($datos['productos']) || ! is_array($datos['productos'])) {
            throw new RuntimeException("La IA respondió algo que no se pudo leer en las páginas {$desde} a {$hasta}.");
        }

        $uso = $respuesta->usage;

        return new BloqueDeCatalogo(
            productos: $datos['productos'],
            uso: new UsoDeTokens(
                entrada: (int) ($uso->inputTokens ?? 0),
                salida: (int) ($uso->outputTokens ?? 0),
                cacheEscritura: (int) ($uso->cacheCreationInputTokens ?? 0),
                cacheLectura: (int) ($uso->cacheReadInputTokens ?? 0),
            ),
        );
    }

    public function borrar(string $archivoIa): void
    {
        $this->claude->files->delete($archivoIa);
    }

    /**
     * Lo que se le pide. Sale de la sección 8 del Diseño del esquema
     * objetivo, que se escribió revisando los dos catálogos reales.
     */
    public static function instruccion(int $desde, int $hasta): string
    {
        return <<<TEXTO
        Estás capturando el catálogo de una fábrica de calzado para una distribuidora.

        Trabaja SOLO con las páginas {$desde} a {$hasta} de este documento. Ignora por completo
        las demás, aunque las veas.

        De esas páginas saca únicamente los productos de CALZADO. No son productos y hay que
        ignorarlos: la portada, el índice, las fotos de ambiente y los artículos que no son
        calzado (lentes, ropa, accesorios).

        Cada color es un producto aparte, con su propio código.

        Reglas para los datos:
        - "pagina": el número de página donde viene el producto.
        - "precio_publico": un solo precio, el de menudeo. Solo el número.
        - "talla_desde" y "talla_hasta": los extremos del rango que aparezca ("22 AL 27" son
          22 y 27; "2 - 6 enteros" son 2 y 6).
        - "medios_numeros": true solo si el catálogo dice que hay medias tallas. Si dice
          "enteros", es false.
        - "disponibilidad": cópiala tal cual si aparece ("hasta agotar existencias").
        - Si un dato no aparece en la página, ponlo en null.

        "dudosos" es lo más importante para quien va a revisar: pon ahí el nombre de cada campo
        que hayas tenido que suponer, deducir o que no se lea bien. Si la marca no aparece en la
        página, déjala en null y pon "marca" en dudosos. Más vale marcar de más que de menos.

        Si en esas páginas no hay ningún producto de calzado, devuelve la lista vacía.
        TEXTO;
    }

    /** El formato exacto que debe devolver. El API lo obliga. */
    public static function esquema(): array
    {
        $texto = ['type' => ['string', 'null']];

        return [
            'type' => 'object',
            'properties' => [
                'productos' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'pagina'          => ['type' => 'integer'],
                            'marca'           => $texto,
                            'modelo'          => $texto,
                            'nombre'          => $texto,
                            'color'           => $texto,
                            'codigo_catalogo' => $texto,
                            'precio_publico'  => ['type' => ['number', 'null']],
                            'talla_desde'     => $texto,
                            'talla_hasta'     => $texto,
                            'medios_numeros'  => ['type' => ['boolean', 'null']],
                            'disponibilidad'  => $texto,
                            'dudosos'         => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                        'required' => [
                            'pagina', 'marca', 'modelo', 'nombre', 'color', 'codigo_catalogo',
                            'precio_publico', 'talla_desde', 'talla_hasta', 'medios_numeros',
                            'disponibilidad', 'dudosos',
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['productos'],
            'additionalProperties' => false,
        ];
    }
}
