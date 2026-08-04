<?php

namespace App\Services\Distribuidora;

use App\Models\Distribuidora;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ActualizarPerfilDistribuidoraAction
{
    public function ejecutar(Distribuidora $distribuidora, array $datos, ?UploadedFile $logo = null): Distribuidora
    {
        if ($logo) {
            // Disco "compatible con S3" configurado en Fase 0 (config/filesystems.php,
            // sección 4 del documento de tareas). El proveedor exacto queda pendiente
            // por presupuesto, pero el disco ya debe apuntar a alguno.
            $ruta = $logo->store('distribuidoras/logotipos', 's3');
            $datos['logotipo_url'] = Storage::disk('s3')->url($ruta);
        }

        $distribuidora->fill($datos);
        $distribuidora->save();

        return $distribuidora->fresh();
    }
}
