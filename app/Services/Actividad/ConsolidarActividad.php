<?php

namespace App\Services\Actividad;

use App\Console\Commands\LimpiarActividad;
use App\Models\ActividadDiaria;
use App\Models\ActividadMensual;
use App\Models\Auditoria;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * TG-406 (A43) — Pasa el detalle a totales por mes y barre lo vencido.
 *
 * El orden importa y no es negociable: PRIMERO se consolida y DESPUÉS se
 * borra. Al revés se perdería el único dato con el que se puede calcular el
 * mes, porque actividad_diaria es la única fuente.
 *
 * Todo es repetible: correrlo dos veces el mismo día deja la base igual que
 * correrlo una. Los totales se actualizan en vez de insertarse de nuevo
 * (índice único por mes y canal) y el borrado solo quita lo que ya venció.
 */
class ConsolidarActividad
{
    /** A partir de aqui, que no haya corrido la limpieza es un problema. */
    public const DIAS_SIN_LIMPIEZA_QUE_PREOCUPAN = 2;

    /**
     * @return array{meses: int, diarias_borradas: int, mensuales_borradas: int}
     */
    public function ejecutar(): array
    {
        $meses = $this->consolidar();

        return [
            'meses' => $meses,
            'diarias_borradas' => $this->borrarDetalleVencido(),
            'mensuales_borradas' => $this->borrarTotalesVencidos(),
        ];
    }

    /**
     * Recalcula los totales de cada mes que todavía tenga detalle.
     *
     * Se recalculan TODOS los meses presentes, no solo los cerrados: el mes en
     * curso también se quiere ver en Métricas, y como es updateOrCreate se
     * corrige solo cada día hasta que el mes termina.
     */
    private function consolidar(): int
    {
        $meses = ActividadDiaria::query()
            ->selectRaw("DATE_FORMAT(fecha, '%Y-%m') as mes")
            ->distinct()
            ->pluck('mes');

        foreach ($meses as $mes) {
            foreach (ActividadDiaria::CANALES as $canal) {
                $this->guardar($mes, $canal, $this->contarUnicos($mes, $canal));
            }

            // El total NO es panel + app: es la gente distinta entre los dos.
            // Quien entra por los dos lados cuenta una vez.
            $this->guardar($mes, ActividadMensual::CANAL_TOTAL, $this->contarUnicos($mes, null));
        }

        return $meses->count();
    }

    /** Personas distintas en ese mes; con $canal null, en cualquier canal. */
    private function contarUnicos(string $mes, ?string $canal): int
    {
        return ActividadDiaria::query()
            ->whereRaw("DATE_FORMAT(fecha, '%Y-%m') = ?", [$mes])
            ->delCanal($canal)
            ->distinct()
            ->count('usuario_id');
    }

    private function guardar(string $mes, string $canal, int $cuantos): void
    {
        ActividadMensual::updateOrCreate(
            ['anio_mes' => $mes, 'canal' => $canal],
            ['usuarios_unicos' => $cuantos, 'calculado_at' => now()],
        );
    }

    private function borrarDetalleVencido(): int
    {
        return ActividadDiaria::query()->vencidas()->delete();
    }

    private function borrarTotalesVencidos(): int
    {
        return ActividadMensual::query()->vencidos()->delete();
    }

    /** Si quedó detalle vencido sin barrer, algo no corrió. */
    public function hayDetalleVencido(): bool
    {
        return ActividadDiaria::query()->vencidas()->exists();
    }

    /** El mes de una fecha, como se guarda. */
    public static function mesDe(Carbon $fecha): string
    {
        return $fecha->format('Y-m');
    }

    /**
     * Cuando corrio bien la ultima limpieza, segun la auditoria.
     *
     * La fuente es la auditoria y no una columna aparte a proposito: la
     * tarjeta ya pide que cada limpieza quede ahi, y tener dos lugares que
     * digan lo mismo termina con uno de los dos mintiendo. Una limpieza
     * FALLIDA no se registra, asi que esto siempre es la ultima buena.
     */
    public function ultimaLimpieza(): ?Carbon
    {
        $cuando = Auditoria::withoutGlobalScopes()
            ->where('accion', LimpiarActividad::ACCION_AUDITORIA)
            ->max('created_at');

        return $cuando ? Carbon::parse($cuando)->timezone(ActividadDiaria::ZONA) : null;
    }

    /**
     * Por que habria que avisar en Metricas, o null si todo esta al dia.
     *
     * Dos motivos, y los dos quieren decir lo mismo: el cron no esta
     * corriendo. Se avisa en pantalla porque un cron que dejo de correr no
     * avisa solo; lo unico que se nota es que los datos se quedan viejos.
     */
    public function motivoDeAlerta(): ?string
    {
        if ($this->hayDetalleVencido()) {
            return 'Hay actividad de más de '.ActividadDiaria::DIAS_QUE_SE_GUARDA
                .' días sin borrar. La limpieza diaria no está corriendo.';
        }

        $ultima = $this->ultimaLimpieza();

        if ($ultima === null) {
            return 'La limpieza diaria todavía no ha corrido ni una vez.';
        }

        if ($ultima->lessThan(now(ActividadDiaria::ZONA)->subDays(self::DIAS_SIN_LIMPIEZA_QUE_PREOCUPAN))) {
            return 'La última limpieza fue el '.$ultima->format('d/m/Y').'. Debería correr todos los días.';
        }

        return null;
    }
}
