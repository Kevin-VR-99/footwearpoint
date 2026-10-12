<?php

namespace App\Services\Catalogo;

use App\Services\Auditoria\AuditarCambios;
use App\Exceptions\OperacionInvalidaException;
use Illuminate\Support\Facades\DB;
use App\Models\Marca;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class GestionarMarcaAction
{
    public function __construct(
        private AuditarCambios $auditar,
        private DependenciasCatalogo $dependencias,
    )
    {
    }

    public function crear(array $datos, ?UploadedFile $logo = null): Marca
    {
        // El cupo del plan cuenta líneas (GestionarLineaAction), no marcas.
        if ($logo) {
            $datos['logotipo_url'] = $this->subirLogo($logo);
        }

        $marca = Marca::create([
            'nombre'       => $datos['nombre'],
            'descripcion'  => $datos['descripcion'] ?? null,
            'logotipo_url' => $datos['logotipo_url'] ?? null,
            'activa'       => true,
        ]);
        $this->auditar->creado($marca, 'catalogo.marca.creada', 'marca');

        return $marca;
    }

    public function actualizar(Marca $marca, array $datos, ?UploadedFile $logo = null): Marca
    {
        if ($logo) {
            $datos['logotipo_url'] = $this->subirLogo($logo);
        }

        $marca->fill($datos);
        $this->auditar->guardar($marca, 'catalogo.marca.actualizada', 'marca');

        return $marca->fresh();
    }

    /**
     * TG-247: borra la marca solo si no tiene productos. Con productos se
     * bloquea: borrarla dejaría productos sin marca (la llave es RESTRICT).
     */
    public function eliminar(Marca $marca): void
    {
        $dependencias = $this->dependencias->deMarca($marca);

        if ($dependencias !== []) {
            throw new OperacionInvalidaException(
                'No se puede eliminar la marca: tiene ' . $this->dependencias->describir($dependencias)
                . '. Desactívala o mueve sus productos a otra marca.'
            );
        }

        DB::transaction(function () use ($marca) {
            $this->auditar->eliminado($marca, 'catalogo.marca.eliminada', 'marca');
            $marca->lineas()->detach();
            $marca->delete();
        });
    }

    private function subirLogo(UploadedFile $logo): string
    {
        $ruta = $logo->store('marcas/logotipos', 's3');

        return Storage::disk('s3')->url($ruta);
    }
}