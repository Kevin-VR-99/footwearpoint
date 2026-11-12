<?php

return [
    /*
    | El comando diario, sin opciones, solo avisa y marca la suscripción
    | vencida. No suspende. Para cortar el acceso al sexto día sin pago:
    | SUSCRIPCION_VENCIMIENTO=suspender
    | o el mismo comando con --suspender.
    */
    'vencimiento' => env('SUSCRIPCION_VENCIMIENTO', 'aviso'),
];
