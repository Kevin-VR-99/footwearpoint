<?php

namespace App\Jobs;

use App\Models\ImportacionCatalogo;
use App\Services\Ia\ExtraerProductosDelCatalogoAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * TG-238 (A8) — Leer un catálogo con la IA, en segundo plano.
 *
 * Va en cola porque no cabe en una petición web: un catálogo de 122 páginas
 * son trece llamadas a la IA y eso tarda varios minutos, mientras que el
 * servidor corta a los 30 segundos. En la cola corre el worker, que no tiene
 * ese límite.
 *
 * Sin reintentos automáticos (tries = 1) a propósito: cada vuelta cuesta
 * dinero de verdad. Si falla, la importación queda en "error" con su motivo y
 * una persona decide si se vuelve a intentar.
 */
class ProcesarCatalogoConIa implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Un catálogo grande puede tardar; el worker no corta a los 30 segundos. */
    public int $timeout = 1800;

    public function __construct(public int $importacionId)
    {
    }

    public function handle(ExtraerProductosDelCatalogoAction $accion): void
    {
        $importacion = ImportacionCatalogo::find($this->importacionId);

        if ($importacion === null) {
            return;
        }

        $accion->ejecutar($importacion);
    }

    /** Si el worker mata la tarea (por tiempo o por caída), que no quede "procesando" para siempre. */
    public function failed(?\Throwable $e): void
    {
        ImportacionCatalogo::where('id', $this->importacionId)
            ->where('estado', 'procesando')
            ->update([
                'estado' => 'error',
                'mensaje_error' => 'El proceso se interrumpió. Puedes volver a intentarlo.',
            ]);
    }
}
