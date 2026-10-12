<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Disco de los catalogos de fabrica (TG-237)
    |--------------------------------------------------------------------------
    |
    | Cual de los discos de abajo guarda los PDF que sube el admin general
    | para la IA. En local y en pruebas, 'local'; en Railway, 'catalogos'
    | cuando el bucket privado este listo.
    |
    */

    'catalogos_disk' => env('CATALOGOS_DISK', 'local'),

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        'productos' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'visibility' => 'public',
            'throw' => false,
        ],

        // TG-237 (A7): los catalogos de fabrica que sube el admin general para
        // que la IA los lea. Bucket PRIVADO: es material del proveedor, no va
        // en el bucket publico de las fotos.
        //
        // CATALOGOS_DISK decide de verdad cual se usa: en local y en pruebas
        // se queda en 'local' y no hace falta configurar nada; en Railway se
        // pone en 'catalogos' cuando el bucket este listo. Ojo: con 'local',
        // en Railway el archivo se borra en cada despliegue.
        'catalogos' => [
            'driver' => 's3',
            'key' => env('CATALOGOS_AWS_ACCESS_KEY_ID'),
            'secret' => env('CATALOGOS_AWS_SECRET_ACCESS_KEY'),
            'region' => env('CATALOGOS_AWS_DEFAULT_REGION', 'auto'),
            'bucket' => env('CATALOGOS_AWS_BUCKET'),
            'endpoint' => env('CATALOGOS_AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('CATALOGOS_AWS_USE_PATH_STYLE_ENDPOINT', true),
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

        // TG-235 (G17): respaldos de la base de datos. Bucket PRIVADO y
        // credenciales propias (nunca el bucket público de las fotos).
        'respaldos' => [
            'driver' => 's3',
            'key' => env('RESPALDO_AWS_ACCESS_KEY_ID'),
            'secret' => env('RESPALDO_AWS_SECRET_ACCESS_KEY'),
            'region' => env('RESPALDO_AWS_DEFAULT_REGION', 'auto'),
            'bucket' => env('RESPALDO_AWS_BUCKET'),
            'endpoint' => env('RESPALDO_AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('RESPALDO_AWS_USE_PATH_STYLE_ENDPOINT', true),
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
