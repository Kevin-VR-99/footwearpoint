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

    /*
    | TG-234: redes sociales de la sección de contacto de la página de inicio.
    | Si una URL queda vacía, ese icono no se muestra.
    */
    'redes' => [
        'instagram' => env('REDES_INSTAGRAM_URL'),
        'facebook' => env('REDES_FACEBOOK_URL'),
        'youtube' => env('REDES_YOUTUBE_URL'),
        // Icono de correo (mailto:). Si falta REDES_CORREO usa el correo de
        // contacto y, al final, el remitente de la app (MAIL_FROM_ADDRESS).
        'correo' => env('REDES_CORREO') ?: env('FOOTWEARPOINT_CONTACTO_EMAIL') ?: env('MAIL_FROM_ADDRESS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Solicitudes de alta (TG-220)
    |--------------------------------------------------------------------------
    |
    | Cuántas solicitudes puede mandar la misma IP en una hora. Es la defensa
    | contra envíos automáticos. Se puede subir para la demostración, donde
    | varias personas prueban desde la misma red.
    |
    */

    'solicitudes_alta' => [
        // Buzón que recibe el aviso de una solicitud nueva. No es el correo
        // con el que se entra al panel (admin general).
        'correo' => env('FOOTWEARPOINT_SOLICITUDES_EMAIL', 'footwearpoint.mx@gmail.com'),

        'por_hora_por_ip' => (int) env('FOOTWEARPOINT_SOLICITUDES_POR_HORA', 3),

        // Segundo límite: intentos de envío en diez minutos, se guarden o no.
        // Evita que se prueben correos sin tope para averiguar quién ya está
        // registrado. Va más holgado, para no estorbarle a quien se equivoca.
        'intentos_por_ip' => (int) env('FOOTWEARPOINT_INTENTOS_SOLICITUD', 10),
    ],

];
