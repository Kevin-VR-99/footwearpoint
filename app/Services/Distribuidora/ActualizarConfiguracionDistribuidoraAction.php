<?php

namespace App\Services\Distribuidora;

use App\Models\ConfiguracionDistribuidora;
use App\Services\Auditoria\AuditarCambios;

class ActualizarConfiguracionDistribuidoraAction
{
    public function ejecutar(array $datos): ConfiguracionDistribuidora
    {
        // Escribe una sola tabla, por eso no lleva DB::transaction (la regla de
        // la sección 1.6 aplica a operaciones que escriben en MÁS de una tabla).
        $config = ConfiguracionDistribuidora::firstOrFail();
        $config->fill($datos);
        // TG-334: solo lo que cambió.
        app(AuditarCambios::class)->guardar($config, 'configuracion.actualizada', 'configuracion_distribuidora');

        return $config->fresh();
    }
}
