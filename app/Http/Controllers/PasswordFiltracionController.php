<?php

namespace App\Http\Controllers;

use App\Services\Auth\ConsultarPasswordFiltrada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aviso de contraseña filtrada: no bloquea el guardado, solo informa.
 */
class PasswordFiltracionController extends Controller
{
    public function __invoke(Request $request, ConsultarPasswordFiltrada $consulta): JsonResponse
    {
        $password = (string) $request->input('password', '');

        if (strlen($password) > 255) {
            return response()->json(['aparecio' => false]);
        }

        return response()->json([
            'aparecio' => $consulta->aparecio($password),
        ]);
    }
}
