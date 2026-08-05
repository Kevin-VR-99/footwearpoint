<?php

namespace App\Services\Ciclo;

use App\Exceptions\OperacionInvalidaException;
use App\Models\CicloCompra;
use App\Models\ConfiguracionCiclo;
use App\Support\ContextoOperativo;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * E10 - Devuelve el ciclo al que debe entrar un pedido nuevo.
 *
 * Lo llama el Paquete D al confirmar un pedido. Reutiliza el ciclo abierto
 * cuya fecha de cierre todavia no pasa; si ya paso, crea el siguiente.
 *
 * NO cambia el estado de ningun ciclo: cerrar es una accion manual y
 * explicita (POST /api/ciclos/{id}/cerrar). Eso significa que un ciclo
 * abierto ya vencido puede convivir un rato con el siguiente, hasta que
 * alguien lo cierre desde la pantalla.
 */
class AsignarCicloVigenteService
{
    public function __construct(private ContextoOperativo $contexto)
    {
    }

    public function paraDistribuidoraActual(): CicloCompra
    {
        return $this->para($this->contexto->distribuidoraId());
    }

    public function para(int $distribuidoraId): CicloCompra
    {
        return DB::transaction(function () use ($distribuidoraId) {
            $config = ConfiguracionCiclo::query()
                ->where('distribuidora_id', $distribuidoraId)
                ->where('activa', true)
                ->orderByDesc('id')
                ->first();

            if ($config === null) {
                throw new OperacionInvalidaException(
                    'La distribuidora no tiene una configuracion de ciclo activa.',
                    409
                );
            }

            $ahora = Carbon::now();

            $vigente = CicloCompra::query()
                ->where('distribuidora_id', $distribuidoraId)
                ->where('estado', 'abierto')
                ->where('fecha_cierre', '>', $ahora)
                ->orderBy('fecha_cierre')
                ->lockForUpdate()
                ->first();

            if ($vigente !== null) {
                return $vigente;
            }

            return $this->crearSiguiente($distribuidoraId, $config, $ahora);
        });
    }

    private function crearSiguiente(
        int $distribuidoraId,
        ConfiguracionCiclo $config,
        Carbon $ahora
    ): CicloCompra {
        $fechaCierre = $this->siguienteOcurrencia(
            $ahora,
            (int) $config->dia_cierre,
            (string) $config->hora_cierre
        );

        // La apertura del ciclo nuevo es el cierre del anterior, para que no
        // queden huecos de tiempo sin ciclo. Si es el primero, es ahora.
        $anterior = CicloCompra::query()
            ->where('distribuidora_id', $distribuidoraId)
            ->orderByDesc('fecha_cierre')
            ->first();

        $fechaApertura = $ahora;

        if ($anterior !== null) {
            $cierreAnterior = Carbon::parse($anterior->fecha_cierre);

            if ($cierreAnterior->lessThan($fechaCierre)) {
                $fechaApertura = $cierreAnterior;
            }
        }

        // chk_ciclo_fechas exige fecha_cierre >= fecha_apertura.
        if ($fechaApertura->greaterThan($fechaCierre)) {
            $fechaApertura = $ahora;
        }

        $ciclo = new CicloCompra();
        $ciclo->forceFill([
            'distribuidora_id' => $distribuidoraId,
            'configuracion_ciclo_id' => $config->id,
            'nombre' => $this->nombreDisponible($distribuidoraId, $fechaCierre),
            'fecha_apertura' => $fechaApertura,
            'fecha_cierre' => $fechaCierre,
            'fecha_solicitud_fabrica' => null,
            'fecha_estimada_llegada' => $this->llegadaProyectada($config, $fechaCierre),
            'fecha_recepcion' => null,
            'estado' => 'abierto',
        ])->save();

        return $ciclo;
    }

    /**
     * dia_cierre y dia_solicitud_fabrica son TINYINT entre 1 y 7. Ningun
     * archivo dice que convencion usan; se asume ISO-8601 (1 = lunes,
     * 7 = domingo), que es la de Carbon::dayOfWeekIso.
     */
    private function siguienteOcurrencia(Carbon $desde, int $diaSemanaIso, string $hora): Carbon
    {
        $partes = array_pad(explode(':', $hora), 3, '00');

        $candidato = $desde->copy()->setTime(
            (int) $partes[0],
            (int) $partes[1],
            (int) $partes[2]
        );

        $diferencia = ($diaSemanaIso - $candidato->dayOfWeekIso + 7) % 7;
        $candidato->addDays($diferencia);

        if ($candidato->lessThanOrEqualTo($desde)) {
            $candidato->addWeek();
        }

        return $candidato;
    }

    /**
     * Proyeccion para que la pantalla del ciclo muestre una llegada estimada
     * ANTES de solicitar a fabrica. Se recalcula con la fecha real cuando se
     * ejecuta POST /api/ciclos/{id}/solicitar-fabrica.
     */
    private function llegadaProyectada(ConfiguracionCiclo $config, Carbon $fechaCierre): string
    {
        $solicitud = $this->siguienteOcurrencia(
            $fechaCierre,
            (int) $config->dia_solicitud_fabrica,
            '00:00:00'
        );

        return $solicitud->copy()
            ->addDays((int) $config->dias_estimados_llegada)
            ->toDateString();
    }

    /**
     * uq_ciclo_tenant_nombre exige nombre unico por distribuidora, y la
     * columna es NOT NULL. Ningun archivo define el formato: se usa la fecha
     * de cierre, con sufijo si ya existe.
     */
    private function nombreDisponible(int $distribuidoraId, CarbonInterface $fechaCierre): string
    {
        $base = 'Ciclo ' . $fechaCierre->toDateString();
        $nombre = $base;
        $sufijo = 1;

        while (
            CicloCompra::query()
                ->where('distribuidora_id', $distribuidoraId)
                ->where('nombre', $nombre)
                ->exists()
        ) {
            $sufijo++;
            $nombre = $base . ' (' . $sufijo . ')';
        }

        return $nombre;
    }
}