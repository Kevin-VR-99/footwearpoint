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

    public const SIN_RESPALDOS = 'No hay ningún respaldo en el bucket para verificar.';

    public const BASE_PRINCIPAL = 'La base de verificación no puede ser la base principal (DB_DATABASE). Usa otra, p. ej. footwearpoint_restaurada.';

    public const BASE_INVALIDA = 'El nombre de la base de verificación solo puede llevar letras, números y guion bajo (máx. 64).';

    public const BASE_EXISTE = 'La base de verificación ya existe. Bórrala a mano o usa --reemplazar si es de un simulacro anterior.';

    public const DESCARGA_FALLO = 'No se pudo descargar o descomprimir el respaldo.';

    public const CARGA_FALLO = 'mysql no pudo cargar el respaldo en la base de verificación.';
}
