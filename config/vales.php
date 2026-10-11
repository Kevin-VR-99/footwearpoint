<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Aviso de vale por vencer (TG-242 / A13 / E16-02)
    |--------------------------------------------------------------------------
    |
    | Con cuantos dias de anticipacion se le avisa a la persona que su vale
    | esta por vencer. El backlog lo dejo "a definir", asi que vive aqui para
    | que el equipo lo mueva sin tocar codigo.
    |
    | Siete dias con vales que duran noventa: alcanza para ir a la tienda sin
    | que el aviso llegue tan pronto que se olvide.
    |
    | Si algun dia cada distribuidora quiere el suyo, hace falta una columna
    | en configuraciones_distribuidora (migracion de Kevin).
    |
    */

    'dias_aviso_vencimiento' => (int) env('VALES_DIAS_AVISO_VENCIMIENTO', 7),

];
