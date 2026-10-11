<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Contacto de FootwearPoint (TG-219)
    |--------------------------------------------------------------------------
    |
    | Lo que muestra la página pública de inicio. No se escribe aquí ningún
    | valor real: salen del entorno, porque cambian entre local y Railway.
    | Un dato vacío simplemente no se muestra.
    |
    */

    'contacto' => [
        'email' => env('FOOTWEARPOINT_CONTACTO_EMAIL'),
        'telefono' => env('FOOTWEARPOINT_CONTACTO_TELEFONO'),
    ],

];
