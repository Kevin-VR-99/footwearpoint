<?php

namespace App\Services\Distribuidora;

use App\Models\ConfiguracionCiclo;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ConfiguracionCicloAction
{
    /**
     * ADVERTENCIA — regla que yo agregué, no viene escrita así en los archivos
     * fuente: solo puede existir UNA configuración de ciclo "activa" a la vez
     * por distribuidora. La agrego porque el servicio AsignarCicloVigente del
     * Paquete C busca "la" configuración activa en singular (sección 4 del
     * documento de tareas), y sin esta regla podría haber dos activas al
     * mismo tiempo y ese servicio tomaría cualquiera de forma ambigua.
     * CONFÍRMALO CON EL EQUIPO antes de dar esto por cerrado — si alguien ya
     * había pensado otra forma de resolverlo, hay que alinearlo con Paquete C.
     */
    public function crear(array $datos): ConfiguracionCiclo
    {
        return DB::transaction(function () use ($datos) {
            $diasRecepcion = $datos['dias_recepcion'];
            unset($datos['dias_recepcion']);

            $marcarActiva = $datos['activa'] ?? true;
            if ($marcarActiva) {
                $this->desactivarConfiguracionVigente();
                $datos['activa'] = true;
            }

            $ciclo = ConfiguracionCiclo::create($datos);
            $this->sincronizarDiasRecepcion($ciclo, $diasRecepcion);

            // TG-334
            app(RegistrarAuditoriaAction::class)->ejecutar('ciclo.configuracion_creada', 'configuracion_ciclo', (int) $ciclo->id, null,
                Arr::except($ciclo->getAttributes(), ['created_at', 'updated_at']) + ['dias_recepcion' => $this->dias($ciclo)]);

            return $ciclo->fresh('diasRecepcion');
        });
    }

    public function actualizar(ConfiguracionCiclo $ciclo, array $datos): ConfiguracionCiclo
    {
        return DB::transaction(function () use ($ciclo, $datos) {
            $diasRecepcion = $datos['dias_recepcion'] ?? null;
            unset($datos['dias_recepcion']);

            if (($datos['activa'] ?? false) === true) {
                $this->desactivarConfiguracionVigente(excepto: $ciclo->id);
            }

            $ciclo->fill($datos);

            // TG-334: el antes y el después solo de lo que cambió, días incluidos.
            $previos = [];
            $nuevos = [];
            foreach (array_diff(array_keys($ciclo->getDirty()), ['created_at', 'updated_at']) as $campo) {
                $previos[$campo] = $ciclo->getRawOriginal($campo);
                $nuevos[$campo] = $ciclo->getAttribute($campo);
            }
            $diasAntes = $this->dias($ciclo);

            $ciclo->save();

            if ($diasRecepcion !== null) {
                $this->sincronizarDiasRecepcion($ciclo, $diasRecepcion);
                $diasDespues = $this->dias($ciclo);
                if ($diasAntes !== $diasDespues) {
                    $previos['dias_recepcion'] = $diasAntes;
                    $nuevos['dias_recepcion'] = $diasDespues;
                }
            }

            if ($nuevos !== []) {
                app(RegistrarAuditoriaAction::class)->ejecutar('ciclo.configuracion_actualizada', 'configuracion_ciclo', (int) $ciclo->id, $previos, $nuevos);
            }

            return $ciclo->fresh('diasRecepcion');
        });
    }

    /** @return list<int> */
    private function dias(ConfiguracionCiclo $ciclo): array
    {
        return $ciclo->diasRecepcion()->orderBy('dia_semana')->pluck('dia_semana')->map(fn ($d) => (int) $d)->all();
    }

    private function desactivarConfiguracionVigente(?int $excepto = null): void
    {
        ConfiguracionCiclo::where('activa', true)
            ->when($excepto, fn ($query) => $query->where('id', '!=', $excepto))
            ->update(['activa' => false]);
    }

    private function sincronizarDiasRecepcion(ConfiguracionCiclo $ciclo, array $dias): void
    {
        // Se reemplaza por completo en vez de hacer diff: la tabla no tiene
        // más datos que el día de la semana, así que no hay nada que perder
        // al borrar y reinsertar dentro de la misma transacción.
        $ciclo->diasRecepcion()->delete();

        $ciclo->diasRecepcion()->createMany(
            collect($dias)->unique()->map(fn ($dia) => ['dia_semana' => $dia])->all()
        );
    }
}
