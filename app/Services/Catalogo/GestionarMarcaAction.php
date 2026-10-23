<?php

namespace App\Services\Catalogo;

use App\Services\Auditoria\AuditarCambios;
use App\Exceptions\OperacionInvalidaException;
use Illuminate\Support\Facades\DB;
use App\Models\Marca;
use Illuminate\Http\UploadedFile;

class GestionarMarcaAction
{
    public function __construct(
        private AuditarCambios $auditar,
        private DependenciasCatalogo $dependencias,
        private LogotipoDeCatalogo $logotipos,
    )
    {
    }

    public function crear(array $datos, ?UploadedFile $logo = null): Marca
    {
        // El cupo del plan cuenta líneas (GestionarLineaAction), no marcas.
        if ($logo) {
            $datos['logotipo_url'] = $this->logotipos->subir($logo, 'marcas');
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

    /**
     * Con $logo se reemplaza el logotipo; con $quitarLogo se quita. En ambos
     * casos el archivo anterior se borra del almacenamiento, y el cambio de
     * logotipo_url queda en la auditoría como cualquier otro campo.
     */
    public function actualizar(Marca $marca, array $datos, ?UploadedFile $logo = null, bool $quitarLogo = false): Marca
    {
        $anterior = $marca->logotipo_url;

        if ($logo) {
            $datos['logotipo_url'] = $this->logotipos->subir($logo, 'marcas');
        } elseif ($quitarLogo) {
            $datos['logotipo_url'] = null;
        }

        $marca->fill($datos);
        $this->auditar->guardar($marca, 'catalogo.marca.actualizada', 'marca');

        if ($anterior !== $marca->logotipo_url) {
            $this->logotipos->borrar($anterior, 'marcas');
        }

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
}