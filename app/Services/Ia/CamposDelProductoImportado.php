<?php

namespace App\Services\Ia;

/**
 * TG-239 (A9) — Los campos de un producto que propuso la IA.
 *
 * Están en un solo sitio porque los usan tres cosas: la pantalla de revisión,
 * la corrección y el relleno en lote. El orden es el que se ve en pantalla.
 */
class CamposDelProductoImportado
{
    /** @var array<string, string> campo => como se llama en pantalla */
    public const NOMBRES = [
        'pagina'          => 'Página',
        'codigo_catalogo' => 'Código',
        'marca'           => 'Marca',
        'modelo'          => 'Modelo',
        'nombre'          => 'Nombre',
        'color'           => 'Color',
        'precio_publico'  => 'Precio',
        'talla_desde'     => 'Talla desde',
        'talla_hasta'     => 'Talla hasta',
        'medios_numeros'  => 'Medias tallas',
        'disponibilidad'  => 'Disponibilidad',
        // TG-240 (A10): la IA no extrae la categoria; se elige al revisar
        // o se hereda la del lote al aprobar.
        'categoria_id'    => 'Categoría',
    ];

    /**
     * Los que se pueden rellenar de golpe en todos los productos de un
     * catálogo: los que un catálogo entero trae o no trae impresos.
     *
     * "medios_numeros" entró con el Impuls: ese catálogo no dice en ninguna
     * página si hay medias tallas, así que la IA lo dejaba en null y lo
     * marcaba dudoso en 23 de 26 productos. Es un dato del catálogo entero,
     * no de cada zapato.
     */
    public const RELLENABLES_EN_LOTE = ['marca', 'disponibilidad', 'medios_numeros'];

    /**
     * Los que no son texto. Importa para dos cosas: cómo se pregunta en
     * pantalla y qué cuenta como "vacío" al rellenar en lote, porque en un
     * booleano false NO es vacío: es una respuesta.
     */
    public const BOOLEANOS = ['medios_numeros'];

    public static function esBooleano(string $campo): bool
    {
        return in_array($campo, self::BOOLEANOS, true);
    }

    /** @return array<int, string> */
    public static function todos(): array
    {
        return array_keys(self::NOMBRES);
    }

    public static function nombreDe(string $campo): string
    {
        return self::NOMBRES[$campo] ?? $campo;
    }
}
