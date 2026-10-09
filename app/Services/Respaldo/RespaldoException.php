<?php

namespace App\Services\Respaldo;

use RuntimeException;

/** TG-235 (G17) — Un respaldo que no se pudo hacer; el mensaje ya está en español. */
class RespaldoException extends RuntimeException
{
    public const SIN_CONFIGURAR = 'Faltan las variables RESPALDO_AWS_* (bucket privado de respaldos). Ver docs/respaldos.md.';

    public const SOLO_MYSQL = 'El respaldo solo funciona con una base de datos MySQL.';

    public const VOLCADO_FALLO = 'mysqldump no pudo volcar la base de datos.';

    public const VOLCADO_INCOMPLETO = 'El volcado salió vacío o incompleto; no se guardó.';

    public const SUBIDA_FALLO = 'No se pudo guardar el respaldo en el bucket.';
}
