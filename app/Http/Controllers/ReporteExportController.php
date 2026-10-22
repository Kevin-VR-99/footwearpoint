<?php

namespace App\Http\Controllers;

use App\Services\Auth\PermisoModuloService;
use App\Services\Reporte\ExportarReporteDistribuidora;
use App\Support\MensajeError;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Descarga del reporte de la distribuidora (PDF o Excel) con los filtros de la pantalla. */
class ReporteExportController extends Controller
{
    private const ESTADOS = ['borrador', 'colocado', 'en_revision', 'confirmado', 'incluido_en_ciclo', 'solicitado_fabrica', 'en_transito', 'recibido_distribuidora', 'parcialmente_disponible', 'listo_entrega', 'vencido_recoleccion', 'entregado', 'rechazado', 'cancelado', 'no_surtido'];

    public function __invoke(Request $request, string $formato, ExportarReporteDistribuidora $exportar): Response
    {
        abort_unless(in_array($formato, ExportarReporteDistribuidora::FORMATOS, true), 404);
        abort_if(Tenant::id() === null, 403, 'No se pudo determinar la distribuidora.');
        // Mismo permiso que la pantalla de reportes (TG-246).
        abort_unless(app(PermisoModuloService::class)->puede($request->user(), PermisoModuloService::REPORTES_FINANCIEROS), 403, MensajeError::SIN_PERMISO);

        $datos = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
            'estado' => ['nullable', 'in:'.implode(',', self::ESTADOS)],
            'ciclo' => ['nullable', 'integer', 'min:1'],
            'temporada' => ['nullable', 'integer', 'min:1'],
            'marca' => ['nullable', 'integer', 'min:1'],
        ]);
        $filtros = [
            'desde' => $datos['desde'] ?? null,
            'hasta' => $datos['hasta'] ?? null,
            'estado' => $datos['estado'] ?? null,
            'ciclo' => isset($datos['ciclo']) ? (int) $datos['ciclo'] : null,
            'temporada' => isset($datos['temporada']) ? (int) $datos['temporada'] : null,
            'marca' => isset($datos['marca']) ? (int) $datos['marca'] : null,
        ];

        // Reportes grandes: a la cola, con aviso en Notificaciones (la pantalla
        // no se queda esperando ni se agota la memoria del contenedor).
        $cola = app(\App\Services\Exportar\ExportacionesEnCola::class);
        if ($cola->reporteVaALaCola($filtros)) {
            $cola->solicitar($request->user(), \App\Services\Exportar\ExportacionesEnCola::TIPO_REPORTE, $formato, $filtros);

            return redirect()->route('reportes.index')->with('exportacion_en_cola', \App\Services\Exportar\ExportacionesEnCola::MENSAJE_EN_COLA);
        }

        $contenido = $formato === 'pdf' ? $exportar->pdf($filtros) : $exportar->xlsx($filtros);
        $tipo = $formato === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return response($contenido, 200, [
            'Content-Type' => $tipo,
            'Content-Disposition' => 'attachment; filename="'.$exportar->nombreArchivo($formato).'"',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
