<?php

/*
 * TG-323 (K24) — Huella del texto de cada versión publicada de los
 * documentos legales. La revisa DocumentosLegalesHuellaTest.
 *
 * Si cambias el texto de un documento:
 *   1. sube su versión y su fecha en config/legal.php ('documentos');
 *   2. agrega aquí la versión nueva con la huella que te da la prueba.
 *
 * NUNCA cambies la huella de una versión que ya se publicó: esa versión ya la
 * aceptaron personas con ese texto. Agrega siempre una versión nueva.
 */

return [
    'aviso_privacidad' => [
        '1.2' => 'fa2b43f430df846ed7b257d439f6480b354b9b09dcfecab57f6b184f07eb4227',
    ],
    'terminos_condiciones' => [
        '1.2' => '36ad8976a1ff26755057701b7d97eb529737138fe6828267c5d9aad68ea339e0',
        // TG-321 (K22)
        '1.3' => 'e80a3547c0ca1be9737a9eae628dc061831eb025cd2ef58b57a95254dc228f97',
    ],
];
