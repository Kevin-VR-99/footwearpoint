<?php

namespace App\Jobs;

use App\Models\ImportacionCatalogo;
use App\Services\Ia\AprobarProductosImportadosAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Auth;

/**
 * TG-377 (A10 / E5-04) — Pasar los productos revisados al catálogo maestro,
 * en segundo plano.
 *
 * POR QUÉ ESTÁ AQUÍ Y NO EN LA PETICIÓN WEB. El 24-nov-2026, en producción,
 * aprobar 30 productos murió con «Maximum execution time of 30 seconds
 * exceeded» dentro de Guzzle. Cada producto hace cuatro viajes al bucket
 * (baja el recorte, sube el original, sube la miniatura, sube la mediana),
 * unos 2.3 s; 13 productos y se acabó el tiempo. Entraron bien, pero la
 * pantalla enseñó un 500. En el worker no hay ese límite: corre con
 * `queue:work --timeout=1800`, igual que la lectura con IA de TG-238.
 *
 * SIN REINTENTOS AUTOMÁTICOS (tries = 1), por lo mismo que la lectura: no
 * porque repetir sea peligroso — aprobarUno() se salta lo que ya tiene
 * producto_campana_creado_id, así que nada se duplicaría —, sino porque un
 * reintento silencioso sobreescribiría el resumen de la vuelta anterior y
 * nadie se enteraría de lo que pasó en la primera.
 */
class AprobarCatalogoImportado implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Un catálogo de 166 productos son varios minutos de subir fotos. */
    public int $timeout = 1800;

    public function __construct(
        public int $importacionId,
        public int $campanaId,
        public int $categoriaPorOmision,
        public int $marcaPorOmision,
        /** Quién presionó el botón. Sin esto la auditoría queda sin dueño. */
        public int $usuarioId,
    ) {
    }

    public function handle(AprobarProductosImportadosAction $accion): void
    {
        $importacion = ImportacionCatalogo::find($this->importacionId);

        if ($importacion === null || $importacion->estado !== 'aprobando') {
            return;
        }

        /*
         * EN LA COLA NO HAY SESIÓN. Toda la auditoría del proyecto sale de
         * Auth::id() (RegistrarAuditoriaAction), así que sin esta línea los
         * 32 productos quedarían registrados como creados por nadie, y la
         * auditoría es justo lo que hace defendible la importación con IA.
         *
         * onceUsingId y no loginUsingId: autentica para este trabajo y ya,
         * sin tocar sesión ni cookies, que en un worker no existen.
         */
        Auth::onceUsingId($this->usuarioId);

        $resumen = $accion->ejecutar(
            $importacion,
            $this->campanaId,
            $this->categoriaPorOmision,
            $this->marcaPorOmision,
        );

        /*
         * ejecutar() ya dejó el estado en «aprobado» si no quedó nada
         * pendiente. Si sí quedó (productos sin revisar, o alguno que falló),
         * la importación vuelve a «requiere_revision»: todavía hay trabajo
         * humano ahí y dejarla en «aprobando» la colgaría para siempre.
         */
        $importacion->refresh();

        $importacion->update([
            'resumen_aprobacion' => $resumen,
            'estado' => $importacion->estado === 'aprobando'
                ? 'requiere_revision'
                : $importacion->estado,
        ]);
    }

    /**
     * Que un worker caído no deje el catálogo colgado en «aprobando».
     *
     * Vuelve a «requiere_revision» y NO a «error», a diferencia de la lectura
     * con IA: lo que alcanzó a entrar está bien guardado (cada producto va en
     * su propia transacción) y lo que falta se arregla presionando otra vez.
     * Marcarlo como error haría pensar que hay algo roto que revisar.
     */
    public function failed(?\Throwable $e): void
    {
        $mensaje = $e instanceof MaxAttemptsExceededException
            ? 'La aprobación se interrumpió porque el servidor se reinició. '
                .'Lo que alcanzó a entrar está guardado; vuelve a presionar «Aprobar los revisados».'
            : 'La aprobación se interrumpió. Lo que alcanzó a entrar está guardado; '
                .'vuelve a presionar «Aprobar los revisados».';

        ImportacionCatalogo::where('id', $this->importacionId)
            ->where('estado', 'aprobando')
            ->update([
                'estado' => 'requiere_revision',
                'mensaje_error' => $mensaje,
            ]);
    }
}
