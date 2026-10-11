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
    ];

    /** Los que se pueden rellenar de golpe en todos los productos de un catálogo. */
    public const RELLENABLES_EN_LOTE = ['marca', 'disponibilidad'];

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
