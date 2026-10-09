<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Importacion de catalogos con IA (TG-238 / A8)
    |--------------------------------------------------------------------------
    |
    | La llave NUNCA va aqui ni en el repositorio: se lee de ANTHROPIC_API_KEY,
    | que en local vive en el .env de cada quien y en produccion en Railway.
    |
    */

    'llave' => env('ANTHROPIC_API_KEY'),

    'modelo' => env('IA_MODELO', 'claude-opus-5-5'),

    /*
    | De cuantas en cuantas paginas se le pide el catalogo. Con 10 el avance se
    | ve seguido y, si un bloque falla, se reintenta solo ese.
    */
    'paginas_por_bloque' => (int) env('IA_PAGINAS_POR_BLOQUE', 10),

    /* Tope de respuesta por bloque. Diez paginas rondan los 4 mil tokens. */
    'max_tokens_por_bloque' => (int) env('IA_MAX_TOKENS_BLOQUE', 16000),

    /*
    | Cuanto vive el archivo subido a Anthropic. Al terminar se borra, pero
    | esto es el respaldo por si el proceso muere a la mitad. El minimo que
    | acepta el API es una hora.
    */
    'horas_del_archivo' => (int) env('IA_HORAS_ARCHIVO', 6),

    /*
    |--------------------------------------------------------------------------
    | Precios, en dolares por millon de tokens
    |--------------------------------------------------------------------------
    |
    | Solo sirven para calcular lo que costo cada importacion y mostrarlo. Son
    | los de claude-opus-5-5 confirmados en la pagina de precios de Anthropic
    | (9-oct-2026). Si cambia el modelo, hay que cambiarlos aqui tambien.
    |
    */
    'precios' => [
        'entrada'         => (float) env('IA_PRECIO_ENTRADA', 4.00),
        'salida'          => (float) env('IA_PRECIO_SALIDA', 20.00),
        'cache_escritura' => (float) env('IA_PRECIO_CACHE_ESCRITURA', 8.00),
        'cache_lectura'   => (float) env('IA_PRECIO_CACHE_LECTURA', 0.20),
    ],

];
