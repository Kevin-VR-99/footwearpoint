<?php

/*
| TG-411 (K46) — Comprobantes de transferencia que sube el cliente.
| - disco: PRIVADO y nunca con URL pública; el archivo lo sirve el servidor.
|   En local y pruebas 'local' (storage/app/private); en Railway
|   COMPROBANTES_DISK=catalogos (bucket privado de R2 que ya existe).
| - Los archivos quedan bajo comprobantes/{distribuidora_id}/{pedido_id}/ para
|   no mezclarse con los catálogos ni con las exportaciones.
| - Cada fila guarda su disco: los comprobantes viejos siguen apuntando al
|   disco 'comprobantes' (config/filesystems.php).
*/
return [
    'disco' => env('COMPROBANTES_DISK', 'local'),

    'prefijo' => 'comprobantes',
];
