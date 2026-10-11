<?php

namespace App\Services\Auditoria;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * TG-267 (U7, E17-03): deja en la auditoría lo que se creó o lo que cambió
 * de un registro, con el antes y el después solo de los campos que se
 * movieron.
 */
class AuditarCambios
{
    private const CAMPOS_IGNORADOS = ['created_at', 'updated_at'];

    public function __construct(private RegistrarAuditoriaAction $auditoria)
    {
    }

    /** Lo recién creado, con sus datos como quedaron guardados. */
    public function creado(Model $modelo, string $accion, string $entidad): void
    {
        $this->auditoria->ejecutar(
            $accion,
            $entidad,
            $modelo->getKey(),
            null,
            Arr::except($modelo->getAttributes(), self::CAMPOS_IGNORADOS),
        );
    }

    /**
     * Guarda el modelo y, si de verdad cambió algo, registra el antes y el
     * después de esos campos. Si no cambió nada, no deja registro.
     */
    public function guardar(Model $modelo, string $accion, string $entidad): void
    {
        $campos = array_values(array_diff(array_keys($modelo->getDirty()), self::CAMPOS_IGNORADOS));

        $previos = [];
        foreach ($campos as $campo) {
            $previos[$campo] = $modelo->getRawOriginal($campo);
        }

        $modelo->save();

        if ($campos === []) {
            return;
        }

        $nuevos = Arr::only($modelo->getAttributes(), $campos);

        $this->auditoria->ejecutar($accion, $entidad, $modelo->getKey(), $previos, $nuevos);
    }

    /** Lo que se borró, con los datos que tenía. */
    public function eliminado(Model $modelo, string $accion, string $entidad): void
    {
        $this->auditoria->ejecutar(
            $accion,
            $entidad,
            $modelo->getKey(),
            Arr::except($modelo->getAttributes(), self::CAMPOS_IGNORADOS),
            null,
        );
    }
}
