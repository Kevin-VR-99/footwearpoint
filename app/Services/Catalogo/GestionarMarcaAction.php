<?php

namespace App\Services\Catalogo;

use App\Models\Marca;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class GestionarMarcaAction
{
    public function crear(array $datos, ?UploadedFile $logo = null): Marca
    {
        // El cupo del plan cuenta líneas (GestionarLineaAction), no marcas.
        if ($logo) {
            $datos['logotipo_url'] = $this->subirLogo($logo);
        }

        return Marca::create([
            'nombre'       => $datos['nombre'],
            'descripcion'  => $datos['descripcion'] ?? null,
            'logotipo_url' => $datos['logotipo_url'] ?? null,
            'activa'       => true,
        ]);
    }

    public function actualizar(Marca $marca, array $datos, ?UploadedFile $logo = null): Marca
    {
        if ($logo) {
            $datos['logotipo_url'] = $this->subirLogo($logo);
        }

        $marca->fill($datos);
        $marca->save();

        return $marca->fresh();
    }

    private function subirLogo(UploadedFile $logo): string
    {
        $ruta = $logo->store('marcas/logotipos', 's3');

        return Storage::disk('s3')->url($ruta);
    }
}