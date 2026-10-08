<?php

/**
 * El catálogo de demostración, sacado de catálogos de fábrica reales (TG-217).
 *
 * Los datos vienen de dos catálogos que mandó el negocio: "Impuls OI26 Niño"
 * (modelos, colores, tallas, códigos y precios tal como vienen impresos) y el
 * de Confort, de donde se tomaron unos modelos de dama. Los PDF NO están en el
 * repositorio: lo que quedó es esta lista.
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
        'linea' => 'Impuls Deportivo',
        'descripcion' => 'Tenis deportivos de marca para niño y joven.',
        'marcas' => ['Nike', 'Adidas', 'Puma'],
        'temporadas' => [
            [
                'nombre' => 'Impuls Otoño-Invierno 2026',
                'estado' => 'activa',
                'inicio' => '-1 month',
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
        'linea' => 'Impuls Escolar',
        'descripcion' => 'Zapato escolar y casual para primaria y secundaria.',
        'marcas' => ['HGN by Mr Shu', 'Destroyer Kids', 'Yuyin'],
        'temporadas' => [
            [
                'nombre' => 'Escolar Otoño-Invierno 2026',
                'estado' => 'activa',
                'inicio' => '-2 months',
                'fin' => '+3 months',
                'productos' => [
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
        ],
    ],
    [
        'linea' => 'Confort Dama',
        'descripcion' => 'Calzado de piel para dama, de la línea Confort.',
        'marcas' => ['Cklass'],
        'temporadas' => [
            [
                'nombre' => 'Confort Otoño-Invierno 2026',
                'estado' => 'activa',
                'inicio' => '-1 month',
                'fin' => '+5 months',
                'productos' => [
                    [
                        'marca' => 'Cklass', 'categoria' => 'Calzado de dama',
                        'modelo' => '355-37', 'nombre' => 'Zapato charol tacón 7 cm',
                        'color' => 'Negro', 'color_comercial' => 'Negro charol',
                        'codigo' => '35537', 'precio' => 699.00,
                        'tallas' => ['2', '3', '4', '5', '6'],
                    ],
                    [
                        'marca' => 'Cklass', 'categoria' => 'Calzado de dama',
                        'modelo' => '488-13', 'nombre' => 'Mocasín charol cuña 3.5 cm',
                        'color' => 'Negro', 'color_comercial' => 'Negro charol',
                        'codigo' => '48813', 'precio' => 649.00,
                        'tallas' => ['2', '3', '4', '5', '6'],
                        'bajo_pedido' => ['6'],
                    ],
                    [
                        'marca' => 'Cklass', 'categoria' => 'Calzado de dama',
                        'modelo' => '355-35', 'nombre' => 'Zapato charol agujeta tacón 7 cm',
                        'color' => 'Negro', 'color_comercial' => 'Negro charol',
                        'codigo' => '35535', 'precio' => 599.00,
                        'tallas' => ['2', '3', '4', '5', '6'],
                    ],
                    [
                        'marca' => 'Cklass', 'categoria' => 'Calzado de dama',
                        'modelo' => '423-48', 'nombre' => 'Zapatilla destalonada 5 cm',
                        'color' => 'Beige', 'color_comercial' => 'Multicolor',
                        'codigo' => '42348', 'precio' => 879.00,
                        'tallas' => ['2', '3', '4', '5', '6', '7'],
                        'no_disponible' => ['7'],
                    ],
                    [
                        'marca' => 'Cklass', 'categoria' => 'Calzado de dama',
                        'modelo' => '423-49', 'nombre' => 'Zapatilla destalonada 5 cm',
                        'color' => 'Negro', 'color_comercial' => 'Negro',
                        'codigo' => '42349', 'precio' => 799.00,
                        'tallas' => ['2', '3', '4', '5', '6', '7'],
                    ],
                ],
            ],
        ],
    ],
];
