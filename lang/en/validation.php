<?php

// La app usa APP_LOCALE=en pero todos los textos al usuario son en español.
// Solo sobreescribimos los mensajes de la regla Password; el resto sigue
// saliendo del framework o de los messages() de cada formulario.
return [
    'password' => [
        'letters' => 'La contraseña debe incluir al menos una letra.',
        'mixed' => 'La contraseña debe incluir mayúsculas y minúsculas.',
        'numbers' => 'La contraseña debe incluir al menos un número.',
        'symbols' => 'La contraseña debe incluir al menos un símbolo.',
        'uncompromised' => 'Esta contraseña apareció en una filtración de datos. Elige otra.',
    ],
];
