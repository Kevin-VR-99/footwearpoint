<?php

/**
 * El catálogo de demostración, sacado de catálogos de fábrica reales (TG-217).
 *
 * Los datos vienen de dos catálogos que mandó el negocio: "Impuls OI26 Niño" y
 * "Cklass Confort OI26" (modelos, colores, tallas, códigos y precios tal como
 * vienen impresos). Los PDF NO están en el repositorio: lo que quedó es esta
 * lista.
 *
 * La línea es la EMPRESA del catálogo y las marcas van dentro de ella (TG-279);
 * una misma marca puede repetirse entre líneas. Por eso Impuls es una sola
 * línea con sus seis marcas, y Cklass es la empresa, no una marca: lo que
 * vende en este catálogo va bajo "8 Days", su colección Confort (calzado
 * ortopédico).
 *
 * Dankriz, Concord y Andrea quedan con su línea y su temporada abiertas, sin
 * productos: se llenan al importar sus catálogos con la IA (A8).
 *
 * Reglas del catálogo que esto respeta (diseño del Sprint 4):
 *  - un producto es un modelo EN UN COLOR, con su propio código y precio (D9);
 *  - sus variantes son las tallas;
 *  - cada línea tiene sus temporadas y solo una activa a la vez (D1, D7);
 *  - el precio es el de menudeo del catálogo, igual para todas (D8).
 *
 * "bajo_pedido" y "no_disponible" son tallas que la fábrica ya no surte igual;
 * están puestas a propósito para que se vea la regla de pedir bajo pedido.
 */
return [
    [
        'linea' => 'Cklass',
        'descripcion' => 'Calzado de dama de Cklass; su colección Confort es la de calzado ortopédico.',
        'marcas' => ['8 Days'],
        'temporadas' => [
            [
                'nombre' => 'Cklass Otoño-Invierno 2026',
                'estado' => 'activa',
                'inicio' => '-1 month',
                'fin' => '+5 months',
                'productos' => [
                    [
                        'marca' => '8 Days', 'categoria' => 'Calzado de dama',
                        'modelo' => '355-43', 'nombre' => 'Balerina tejida de piel',
                        'color' => 'Café', 'color_comercial' => 'Miel',
                        'codigo' => '35543', 'precio' => 629.00,
                        'tallas' => ['2', '3', '4', '5', '6'],
                    ],
                    [
                        'marca' => '8 Days', 'categoria' => 'Calzado de dama',
                        'modelo' => '106-32', 'nombre' => 'Balerina tejida con correa',
                        'color' => 'Beige', 'color_comercial' => 'Multicolor',
                        'codigo' => '10632', 'precio' => 699.00,
                        'tallas' => ['3', '4', '5', '6'],
                        'bajo_pedido' => ['6'],
                    ],
                    [
                        'marca' => '8 Days', 'categoria' => 'Calzado de dama',
                        'modelo' => '284-35', 'nombre' => 'Zapato destalonado perforado 3 cm',
                        'color' => 'Café', 'color_comercial' => 'Shedrón',
                        'codigo' => '28435', 'precio' => 849.00,
                        'tallas' => ['2', '3', '4', '5', '6'],
                    ],
                    [
                        'marca' => '8 Days', 'categoria' => 'Calzado de dama',
                        'modelo' => '200-39', 'nombre' => 'Sandalia de cuña 3.5 cm',
                        'color' => 'Café', 'color_comercial' => 'Miel',
                        'codigo' => '20039', 'precio' => 699.00,
                        'tallas' => ['3', '4', '5', '6'],
                    ],
                    [
                        'marca' => '8 Days', 'categoria' => 'Calzado de dama',
                        'modelo' => '215-63', 'nombre' => 'Sandalia de cuña de yute 7 cm',
                        'color' => 'Azul', 'color_comercial' => 'Marino',
                        'codigo' => '21563', 'precio' => 749.00,
                        'tallas' => ['2', '3', '4', '5', '6'],
                        'no_disponible' => ['6'],
                    ],
                ],
            ],
        ],
    ],
    [
        'linea' => 'Impuls',
        'descripcion' => 'Calzado deportivo, escolar y casual para niño y joven.',
        'marcas' => ['Nike', 'Adidas', 'Puma', 'HGN by Mr Shu', 'Destroyer Kids', 'Yuyin'],
        'temporadas' => [
            [
                // TG-279: antes esto estaba partido en "Impuls Deportivo" e
                // "Impuls Escolar". Es una sola empresa, así que es una sola
                // línea con una sola temporada activa (D7).
                'nombre' => 'Impuls Otoño-Invierno 2026',
                'estado' => 'activa',
                'inicio' => '-2 months',
                'fin' => '+4 months',
                'productos' => [

                    [
                        'marca' => 'Nike', 'categoria' => 'Calzado deportivo',
                        'modelo' => 'IR1458102', 'nombre' => 'Nike Reax 8 NS SL',
                        'color' => 'Blanco', 'color_comercial' => 'Blanco/Plata',
                        'codigo' => '889879', 'precio' => 2879.00,
                        'tallas' => ['22', '23', '24', '25', '26', '27'],
                        'bajo_pedido' => ['26', '27'],
                        'imagen' => 'IR1458102.jpg',
                    ],
                    [
                        'marca' => 'Nike', 'categoria' => 'Calzado deportivo',
                        'modelo' => 'IR1458006', 'nombre' => 'Nike Reax 8 NS SL',
                        'color' => 'Negro', 'color_comercial' => 'Negro',
                        'codigo' => '889861', 'precio' => 2879.00,
                        'tallas' => ['22', '23', '24', '25', '26', '27'],
                        'imagen' => 'IR1458006.jpg',
                    ],
                    [
                        'marca' => 'Nike', 'categoria' => 'Calzado deportivo',
                        'modelo' => 'IR0818007', 'nombre' => 'Nike Air Max Fire',
                        'color' => 'Negro', 'color_comercial' => 'Negro',
                        'codigo' => '889842', 'precio' => 2739.00,
                        'tallas' => ['22', '23', '24', '25', '26', '27'],
                        'no_disponible' => ['27'],
                        'imagen' => 'IR0818007.jpg',
                    ],
                    [
                        'marca' => 'Nike', 'categoria' => 'Calzado deportivo',
                        'modelo' => 'DH3158003', 'nombre' => 'Nike Court Legacy Next Nature',
                        'color' => 'Blanco', 'color_comercial' => 'Blanco/Negro',
                        'codigo' => '833222', 'precio' => 2229.00,
                        'tallas' => ['22', '23', '24', '25', '26', '27'],
                    ],
                    [
                        'marca' => 'Nike', 'categoria' => 'Calzado deportivo',
                        'modelo' => 'DH3158012', 'nombre' => 'Nike Court Legacy Next Nature',
                        'color' => 'Beige', 'color_comercial' => 'Beige/Guinda',
                        'codigo' => '889641', 'precio' => 2229.00,
                        'tallas' => ['22', '23', '24', '25'],
                        'bajo_pedido' => ['25'],
                    ],
                    [
                        'marca' => 'Adidas', 'categoria' => 'Calzado deportivo',
                        'modelo' => 'JR4616', 'nombre' => 'Adidas Grand Court Base 3.0',
                        'color' => 'Blanco', 'color_comercial' => 'Blanco/Negro',
                        'codigo' => '887258', 'precio' => 1699.00,
                        'tallas' => ['22', '23', '24', '25', '26', '27'],
                        'imagen' => 'JR4616.jpg',
                    ],
                    [
                        'marca' => 'Adidas', 'categoria' => 'Calzado deportivo',
                        'modelo' => 'HQ0095', 'nombre' => 'Adidas Grand Court Base 3.0',
                        'color' => 'Negro', 'color_comercial' => 'Negro/Blanco',
                        'codigo' => '887228', 'precio' => 1699.00,
                        'tallas' => ['22', '23', '24', '25', '26', '27'],
                        'bajo_pedido' => ['22'],
                        'imagen' => 'HQ0095.jpg',
                    ],
                    [
                        'marca' => 'Adidas', 'categoria' => 'Calzado deportivo',
                        'modelo' => 'JQ7143', 'nombre' => 'Adidas Park St 2.0',
                        'color' => 'Negro', 'color_comercial' => 'Negro',
                        'codigo' => '887239', 'precio' => 1589.00,
                        'tallas' => ['22', '23', '24', '25', '26'],
                    ],
                    [
                        'marca' => 'Puma', 'categoria' => 'Calzado deportivo',
                        'modelo' => '39618102', 'nombre' => 'Puma Caven 2.0',
                        'color' => 'Blanco', 'color_comercial' => 'Blanco/Plata',
                        'codigo' => '790609', 'precio' => 2119.00,
                        'tallas' => ['22', '23', '24', '25', '26', '27'],
                    ],
                    [
                        'marca' => 'Puma', 'categoria' => 'Calzado deportivo',
                        'modelo' => '40259701', 'nombre' => 'Puma Shuffle Downtown',
                        'color' => 'Gris', 'color_comercial' => 'Vapor/Negro/Oro',
                        'codigo' => '863941', 'precio' => 1869.00,
                        'tallas' => ['22', '23', '24', '25'],
                        'no_disponible' => ['22'],
                    ],

                    [
                        'marca' => 'HGN by Mr Shu', 'categoria' => 'Calzado escolar',
                        'modelo' => '468-GRIS', 'nombre' => 'Escolar HGN 468',
                        'color' => 'Gris', 'color_comercial' => 'Gris',
                        'codigo' => '848159', 'precio' => 549.00,
                        'tallas' => ['22', '23', '24', '25'],
                    ],
                    [
                        'marca' => 'HGN by Mr Shu', 'categoria' => 'Calzado escolar',
                        'modelo' => '468-NEGRO', 'nombre' => 'Escolar HGN 468',
                        'color' => 'Negro', 'color_comercial' => 'Negro',
                        'codigo' => '848178', 'precio' => 549.00,
                        'tallas' => ['22', '23', '24', '25'],
                        'bajo_pedido' => ['25'],
                    ],
                    [
                        'marca' => 'HGN by Mr Shu', 'categoria' => 'Calzado casual',
                        'modelo' => '311', 'nombre' => 'Casual HGN 311',
                        'color' => 'Blanco', 'color_comercial' => 'Blanco/Blanco',
                        'codigo' => '839727', 'precio' => 589.00,
                        'tallas' => ['22', '23', '24', '25'],
                    ],
                    [
                        'marca' => 'HGN by Mr Shu', 'categoria' => 'Calzado casual',
                        'modelo' => '1717', 'nombre' => 'Casual HGN 1717',
                        'color' => 'Blanco', 'color_comercial' => 'Blanco',
                        'codigo' => '594049', 'precio' => 549.00,
                        'tallas' => ['22', '23', '24', '25', '26'],
                        'no_disponible' => ['26'],
                    ],
                    [
                        'marca' => 'Destroyer Kids', 'categoria' => 'Calzado escolar',
                        'modelo' => '1182', 'nombre' => 'Escolar Destroyer 1182',
                        'color' => 'Negro', 'color_comercial' => 'Negro',
                        'codigo' => '866252', 'precio' => 629.00,
                        'tallas' => ['22', '23', '24', '25'],
                    ],
                    [
                        'marca' => 'Yuyin', 'categoria' => 'Calzado escolar',
                        'modelo' => '24293', 'nombre' => 'Escolar Yuyin 24293',
                        'color' => 'Negro', 'color_comercial' => 'Negro',
                        'codigo' => '788197', 'precio' => 779.00,
                        'tallas' => ['22', '23'],
                        'bajo_pedido' => ['23'],
                    ],
                ],
            ],
            [
                // Una temporada que ya pasó: solo una puede estar activa (D7).
                'nombre' => 'Impuls Primavera-Verano 2026',
                'estado' => 'finalizada',
                'inicio' => '-9 months',
                'fin' => '-3 months',
                'productos' => [

                    [
                        'marca' => 'Puma', 'categoria' => 'Calzado deportivo',
                        'modelo' => '38528301', 'nombre' => 'Puma Smash 3.0',
                        'color' => 'Azul', 'color_comercial' => 'Azul/Blanco',
                        'codigo' => '780112', 'precio' => 1599.00,
                        'tallas' => ['22', '23', '24', '25'],
                    ],
                ],
            ],
        ],
    ],
    [
        'linea' => 'Dankriz',
        'descripcion' => 'Calzado de dama. Su catálogo se carga con la IA.',
        'marcas' => [],
        'temporadas' => [
            [
                'nombre' => 'Dankriz Otoño-Invierno 2026',
                'estado' => 'activa',
                'inicio' => '-1 month',
                'fin' => '+5 months',
                'productos' => [],
            ],
        ],
    ],
    [
        'linea' => 'Concord',
        'descripcion' => 'Calzado de caballero. Su catálogo se carga con la IA.',
        'marcas' => [],
        'temporadas' => [
            [
                'nombre' => 'Concord Otoño-Invierno 2026',
                'estado' => 'activa',
                'inicio' => '-1 month',
                'fin' => '+5 months',
                'productos' => [],
            ],
        ],
    ],
    [
        'linea' => 'Andrea',
        'descripcion' => 'Calzado para toda la familia. Su catálogo se carga con la IA.',
        'marcas' => [],
        'temporadas' => [
            [
                'nombre' => 'Andrea Otoño-Invierno 2026',
                'estado' => 'activa',
                'inicio' => '-1 month',
                'fin' => '+5 months',
                'productos' => [],
            ],
        ],
    ],
];
