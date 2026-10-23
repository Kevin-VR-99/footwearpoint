<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auth\PermisoModuloService;
use App\Services\Reporte\DemandaPorTemporadaMarcaAction;
use App\Services\Reporte\MontosPendientesAction;
use App\Services\Reporte\PedidosPorRevendedorAction;
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

    /**
     * TG-251: pedidos y montos por cliente mayorista. Solo con el permiso
     * 'reportes.ver_financieros' (el admin siempre lo tiene).
     */
    public function pedidosPorClienteMayorista(Request $request, PedidosPorRevendedorAction $accion, PermisoModuloService $permisos): JsonResponse
    {
        abort_if(Tenant::id() === null, 403, 'No se pudo determinar la distribuidora.');
        abort_unless($permisos->puede($request->user(), PermisoModuloService::REPORTES_FINANCIEROS), 403, 'No tienes permiso para ver reportes financieros.');

        $datos = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'ciclo_id' => ['nullable', 'integer', 'min:1'],
        ], [
            'hasta.after_or_equal' => 'La fecha "hasta" no puede ser anterior a "desde".',
            'desde.date_format' => 'Usa el formato AAAA-MM-DD en "desde".',
            'hasta.date_format' => 'Usa el formato AAAA-MM-DD en "hasta".',
        ]);

        return response()->json([
            'data' => $accion->ejecutar($datos['desde'] ?? null, $datos['hasta'] ?? null, isset($datos['ciclo_id']) ? (int) $datos['ciclo_id'] : null),
        ]);
    }

    /**
     * TG-252: cuánto falta por cobrar (foto al día de hoy) y vales vigentes.
     * Solo con el permiso 'reportes.ver_financieros'.
     */
    public function montosPendientes(Request $request, MontosPendientesAction $accion, PermisoModuloService $permisos): JsonResponse
    {
        abort_if(Tenant::id() === null, 403, 'No se pudo determinar la distribuidora.');
        abort_unless($permisos->puede($request->user(), PermisoModuloService::REPORTES_FINANCIEROS), 403, 'No tienes permiso para ver reportes financieros.');

        $datos = $request->validate([
            'ciclo_id' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json([
            'data' => $accion->ejecutar(isset($datos['ciclo_id']) ? (int) $datos['ciclo_id'] : null),
        ]);
    }

    /**
     * TG-253: productos más solicitados y marcas con más demanda, por
     * temporada. Solo con el permiso 'reportes.ver_financieros'.
     */
    public function demanda(Request $request, DemandaPorTemporadaMarcaAction $accion, PermisoModuloService $permisos): JsonResponse
    {
        abort_if(Tenant::id() === null, 403, 'No se pudo determinar la distribuidora.');
        abort_unless($permisos->puede($request->user(), PermisoModuloService::REPORTES_FINANCIEROS), 403, 'No tienes permiso para ver reportes financieros.');

        $datos = $request->validate([
            'temporada_id' => ['nullable', 'integer', 'min:1'],
            // Compatibilidad: antes se filtraba por la temporada de una línea.
            'campana_id' => ['nullable', 'integer', 'min:1'],
            'marca_id' => ['nullable', 'integer', 'min:1'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'ciclo_id' => ['nullable', 'integer', 'min:1'],
        ], [
            'hasta.after_or_equal' => 'La fecha "hasta" no puede ser anterior a "desde".',
            'desde.date_format' => 'Usa el formato AAAA-MM-DD en "desde".',
            'hasta.date_format' => 'Usa el formato AAAA-MM-DD en "hasta".',
        ]);

        $entero = fn (string $k) => isset($datos[$k]) ? (int) $datos[$k] : null;

        return response()->json([
            'data' => $accion->ejecutar($entero('temporada_id'), $entero('marca_id'), $datos['desde'] ?? null, $datos['hasta'] ?? null, $entero('ciclo_id'), $entero('campana_id')),
        ]);
    }
}
