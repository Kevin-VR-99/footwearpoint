<?php

namespace App\Services\Exportar;

use App\Jobs\GenerarExportacion;
use App\Models\Exportacion;
use App\Models\Usuario;
use App\Services\Reporte\ExportarReporteDistribuidora;
use App\Support\Tenant;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;

/**
 * Decide si una exportación se descarga al momento o se manda a la cola, la
 * registra y arma el enlace firmado de descarga. Quien llama ya revisó los
 * permisos de la pantalla; aquí solo se amarra a la persona y a su
 * distribuidora.
 */
class ExportacionesEnCola
{
    public const TIPO_REPORTE = 'reporte';

    /** tipo => formatos permitidos */
    public const TIPOS = [
        self::TIPO_REPORTE => ExportarReporteDistribuidora::FORMATOS,
        ExportarPedidos::TIPO => ['xlsx'],
    ];

    public const MENSAJE_EN_COLA = 'Es un archivo grande: lo estamos preparando. Te avisaremos en Notificaciones con el enlace de descarga.';

    public function pedidosVanALaCola(array $filtros): bool
    {
        return app(ExportarPedidos::class)->total($filtros) > (int) config('exportaciones.umbral_listado');
    }

    public function reporteVaALaCola(array $filtros): bool
    {
        return app(ExportarReporteDistribuidora::class)->filasEstimadas($filtros) > (int) config('exportaciones.umbral_reporte');
    }

    public function solicitar(Usuario $usuario, string $tipo, string $formato, array $filtros): Exportacion
    {
        if (! in_array($formato, self::TIPOS[$tipo] ?? [], true)) {
            throw new InvalidArgumentException("Exportación no soportada: {$tipo}/{$formato}.");
        }

        $distribuidoraId = Tenant::id();
        abort_if($distribuidoraId === null, 403, 'No se pudo determinar la distribuidora.');

        $exportacion = Exportacion::create([
            'distribuidora_id' => $distribuidoraId,
            'usuario_id' => $usuario->id,
            'tipo' => $tipo,
            'formato' => $formato,
            'filtros' => $filtros,
            'estado' => Exportacion::PENDIENTE,
        ]);

        GenerarExportacion::dispatch($exportacion->id)->afterCommit();

        return $exportacion;
    }

    /** Enlace firmado que caduca en config('exportaciones.minutos_enlace') minutos. */
    public static function enlace(Exportacion $exportacion): string
    {
        return URL::temporarySignedRoute(
            'exportaciones.descargar',
            now()->addMinutes((int) config('exportaciones.minutos_enlace', 30)),
            ['exportacion' => $exportacion->id],
        );
    }
}
