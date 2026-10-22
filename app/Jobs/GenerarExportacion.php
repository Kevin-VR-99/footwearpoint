<?php

namespace App\Jobs;

use App\Models\Exportacion;
use App\Models\Notificacion;
use App\Models\Usuario;
use App\Services\Exportar\ExportacionesEnCola;
use App\Services\Exportar\ExportarPedidos;
use App\Services\Reporte\ExportarReporteDistribuidora;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Arma en la cola una exportación grande (reporte PDF/Excel o listado de
 * pedidos) con la misma distribuidora y el mismo usuario que la pidieron,
 * la guarda en el disco privado y avisa en Notificaciones.
 */
class GenerarExportacion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(public int $exportacionId) {}

    public function handle(): void
    {
        $exportacion = Exportacion::withoutGlobalScopes()->find($this->exportacionId);

        if ($exportacion === null || $exportacion->estado === Exportacion::LISTA) {
            return;
        }

        $usuario = Usuario::find($exportacion->usuario_id);
        if ($usuario === null) {
            $exportacion->update(['estado' => Exportacion::FALLIDA, 'error' => 'El usuario ya no existe.']);

            return;
        }

        $exportacion->update(['estado' => Exportacion::PROCESANDO]);

        $this->comoQuienLaPidio($usuario, (int) $exportacion->distribuidora_id, function () use ($exportacion) {
            [$contenido, $nombre] = $this->generar($exportacion);

            $disco = (string) config('exportaciones.disco');
            $ruta = 'exportaciones/'.$exportacion->distribuidora_id.'/'.Str::uuid().'.'.$exportacion->formato;
            Storage::disk($disco)->put($ruta, $contenido, ['visibility' => 'private']);

            $exportacion->update([
                'estado' => Exportacion::LISTA,
                'disco' => $disco,
                'ruta' => $ruta,
                'nombre_archivo' => $nombre,
                'bytes' => strlen($contenido),
                'error' => null,
                'expira_at' => now()->addHours((int) config('exportaciones.horas_vigencia', 24)),
            ]);

            $this->avisar($exportacion, 'exportacion_lista', 'Tu archivo está listo',
                'Ya puedes descargar '.$nombre.'. El archivo estará disponible '.(int) config('exportaciones.horas_vigencia', 24).' horas.');
        });
    }

    public function failed(?Throwable $e): void
    {
        $exportacion = Exportacion::withoutGlobalScopes()->find($this->exportacionId);
        if ($exportacion === null) {
            return;
        }

        $exportacion->update(['estado' => Exportacion::FALLIDA, 'error' => mb_substr('No se pudo generar el archivo.', 0, 255)]);
        report($e);
        $this->avisar($exportacion, 'exportacion_fallida', 'No se pudo generar tu archivo',
            'Intenta de nuevo con un periodo más corto. Si se repite, avísanos.');
    }

    /** @return array{0: string, 1: string} contenido y nombre del archivo */
    private function generar(Exportacion $e): array
    {
        $filtros = (array) $e->filtros;

        return match ($e->tipo) {
            ExportacionesEnCola::TIPO_REPORTE => [
                $e->formato === 'pdf' ? app(ExportarReporteDistribuidora::class)->pdf($filtros) : app(ExportarReporteDistribuidora::class)->xlsx($filtros),
                app(ExportarReporteDistribuidora::class)->nombreArchivo($e->formato),
            ],
            ExportarPedidos::TIPO => [app(ExportarPedidos::class)->contenido($filtros), app(ExportarPedidos::class)->nombreArchivo()],
        };
    }

    /** Corre el callback con el tenant y el usuario de la exportación, y los suelta al terminar. */
    private function comoQuienLaPidio(Usuario $usuario, int $distribuidoraId, callable $callback): void
    {
        // Con QUEUE_CONNECTION=sync el job corre dentro de la petición web:
        // se guarda y se regresa la sesión que hubiera, sin cerrarla.
        $guard = Auth::guard();
        $anterior = $guard->user();

        Auth::setUser($usuario);
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        try {
            Tenant::forzar($distribuidoraId, $callback);
        } finally {
            if ($anterior !== null) {
                Auth::setUser($anterior);
            } elseif (method_exists($guard, 'forgetUser')) {
                $guard->forgetUser();
            }
            Tenant::olvidarCache();
            PropietarioActual::olvidarCache();
        }
    }

    private function avisar(Exportacion $e, string $tipo, string $titulo, string $mensaje): void
    {
        Notificacion::withoutGlobalScopes()->create([
            'usuario_id' => $e->usuario_id,
            'distribuidora_id' => $e->distribuidora_id,
            'tipo' => $tipo,
            'titulo' => $titulo,
            'mensaje' => $mensaje,
            'entidad_tipo' => 'exportacion',
            'entidad_id' => $e->id,
        ]);
    }
}
