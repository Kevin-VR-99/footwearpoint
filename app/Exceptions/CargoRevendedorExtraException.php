<?php

namespace App\Exceptions;

/**
 * El revendedor que se quiere agregar ya no cabe en el plan y nadie aceptó
 * el cargo del revendedor extra. El mensaje dice cuánto cuesta; la pantalla
 * lo muestra y pide confirmarlo. En la API responde 409 como cualquier
 * OperacionInvalidaException.
 */
class CargoRevendedorExtraException extends OperacionInvalidaException
{
}
