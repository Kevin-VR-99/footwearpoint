<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Reporte\ResumenOperativoAction;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReporteController extends Controller
{
    public function resumen(Request $request, ResumenOperativoAction $accion): JsonResponse
    {
        abort_if(Tenant::id() === null, 403, 'No se pudo determinar la distribuidora.');

        $desde = $request->query('desde');
        $hasta = $request->query('hasta');

        return response()->json([
            'data' => $accion->ejecutar($desde, $hasta),
        ]);
    }
}