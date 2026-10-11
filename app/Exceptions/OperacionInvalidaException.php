<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Respeta el formato de error de la seccion 1.7 sin tocar bootstrap/app.php:
 * Laravel llama solo al metodo render() de la excepcion.
 *
 * TG-224 (G3): su mensaje esta escrito para el usuario, asi que se puede
 * mostrar tal cual (ver MensajeParaUsuario).
 */
class OperacionInvalidaException extends Exception implements MensajeParaUsuario
{
    public function __construct(
        string $message,
        private int $estadoHttp = 409,
        private array $errores = []
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        $cuerpo = ['message' => $this->getMessage()];

        if ($this->errores !== []) {
            $cuerpo['errors'] = $this->errores;
        }

        return response()->json($cuerpo, $this->estadoHttp);
    }
}