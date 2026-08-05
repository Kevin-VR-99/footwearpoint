<?php

namespace App\Services\Distribuidora;

use App\Models\ConfiguracionDistribuidora;

class ActualizarConfiguracionDistribuidoraAction
{
    public function ejecutar(array $datos): ConfiguracionDistribuidora
    {
        // Escribe una sola tabla, por eso no lleva DB::transaction (la regla de
        // la sección 1.6 aplica a operaciones que escriben en MÁS de una tabla).
        $config = ConfiguracionDistribuidora::firstOrFail();
        $config->fill($datos);
        $config->save();

        return $config->fresh();
    }
}
