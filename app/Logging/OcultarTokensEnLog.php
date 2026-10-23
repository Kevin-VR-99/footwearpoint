<?php

namespace App\Logging;

use App\Support\OcultarTokensEnUrl;
use Illuminate\Log\Logger;
use Monolog\LogRecord;

/**
 * "tap" de los canales de log: quita tokens de restablecimiento y firmas de
 * las direcciones que aparezcan en el mensaje o en el contexto.
 */
class OcultarTokensEnLog
{
    public function __invoke(Logger $logger): void
    {
        $logger->getLogger()->pushProcessor(function (LogRecord $registro): LogRecord {
            return $registro->with(
                message: OcultarTokensEnUrl::texto($registro->message),
                context: OcultarTokensEnUrl::arreglo($registro->context),
                extra: OcultarTokensEnUrl::arreglo($registro->extra),
            );
        });
    }
}
