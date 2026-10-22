<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Comision\ComisionesService;
use App\Support\PropietarioActual;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Comisiones y metas: el cliente mayorista ve lo suyo; el personal, el ranking. */
class ComisionController extends Controller
{
    /** GET /api/revendedor/comisiones?periodo=AAAA-MM */
    public function mias(Request $request, ComisionesService $servicio): JsonResponse
    {
        $propietario = PropietarioActual::actual();
        abort_unless(($propietario['tipo'] ?? null) === 'revendedor', 403);

        $periodo = ComisionesService::periodoValido($request->query('periodo'));
        $fila = $servicio->reporte($periodo, (int) $propietario['id'])[0] ?? null;
        abort_if($fila === null, 404);

        // Su lugar en el ranking (sin ver a los demás).
        $lugar = collect($servicio->reporte($periodo))->firstWhere('afiliacion_id', (int) $propietario['id'])['lugar'] ?? null;

        return response()->json(['data' => [
            'periodo'         => $periodo,
            'porcentaje'      => $fila['porcentaje'],
            'pedidos_pagados' => $fila['pedidos_pagados'],
            'venta_pagada'    => $fila['venta_pagada'],
            'comision'        => $fila['comision'],
            'meta'            => $fila['meta'],
            'avance'          => $fila['avance'],
            'lugar'           => $lugar,
        ]]);
    }

    /** GET /api/distribuidora/comisiones?periodo=AAAA-MM */
    public function ranking(Request $request, ComisionesService $servicio): JsonResponse
    {
        $servicio->exigirVer();
        $periodo = ComisionesService::periodoValido($request->query('periodo'));

        return response()->json(['data' => ['periodo' => $periodo, 'ranking' => $servicio->reporte($periodo)]]);
    }
}
