<?php

namespace App\Services\Catalogo;

use App\Services\Auditoria\AuditarCambios;
use App\Exceptions\OperacionInvalidaException;
use App\Models\Campana;
use Illuminate\Support\Facades\DB;

class GestionarCampanaAction
{
    public function __construct(
        private AuditarCambios $auditar,
        private DependenciasCatalogo $dependencias,
    )
    {
    }

    /**
     * Orden real de los 6 estados, tal como está documentado en el modelo
     * v3. Solo se permite avanzar UN paso a la vez en este orden — no
     * saltar estados ni retroceder.
     */
    public const ORDEN_ESTADOS = [
        'borrador',
        'en_importacion',
        'en_revision',
        'activa',
        'finalizada',
        'archivada',
    ];

    public function crear(array $datos): Campana
    {
        // Corrección: se escribe 'borrador' explícito, sin confiar en que
        // la base de datos aplique su propio valor por default — Eloquent
        // puede mandar NULL de forma explícita y eso le "gana" al default
        // de la columna.
        $campana = Campana::create([
            'linea_id'     => $datos['linea_id'],
            'nombre'       => $datos['nombre'],
            'descripcion'  => $datos['descripcion'] ?? null,
            'fecha_inicio' => $datos['fecha_inicio'] ?? null,
            'fecha_fin'    => $datos['fecha_fin'] ?? null,
            'estado'       => 'borrador',
        ]);
        $this->auditar->creado($campana, 'catalogo.temporada.creada', 'campana');

        return $campana;
    }

    /**
     * DECISIÓN PROVISIONAL MÍA (no viene escrita así en ningún documento):
     * solo se permite avanzar al SIGUIENTE estado de la secuencia, nunca
     * saltar ni retroceder. El documento solo dice "controla la transición
     * de estado" sin especificar si retroceder alguna vez es válido (por
     * ejemplo, de 'activa' de vuelta a 'en_revision' si se detecta un
     * error antes de que alguien la use). Confirma con el equipo si hace
     * falta permitir retrocesos.
     */
    public function actualizar(Campana $campana, array $datos): Campana
    {
        if (isset($datos['estado']) && $datos['estado'] !== $campana->estado) {
            $this->validarTransicion($campana->estado, $datos['estado']);
        }

        // linea_id no se acepta aquí: una temporada no cambia de línea.
        unset($datos['linea_id']);
        $campana->fill($datos);
        $this->auditar->guardar($campana, 'catalogo.temporada.actualizada', 'campana');

        return $campana->fresh();
    }

    /**
     * TG-247: borra la temporada solo si no tiene productos publicados ni
     * importaciones. La temporada no tiene campo activo: la alternativa es
     * avanzarla a finalizada o archivada.
     */
    public function eliminar(Campana $campana): void
    {
        $dependencias = $this->dependencias->deCampana($campana);

        if ($dependencias !== []) {
            throw new OperacionInvalidaException(
                'No se puede eliminar la temporada: tiene ' . $this->dependencias->describir($dependencias)
                . '. Quita primero sus productos o archívala.'
            );
        }

        DB::transaction(function () use ($campana) {
            $this->auditar->eliminado($campana, 'catalogo.temporada.eliminada', 'campana');
            $campana->delete();
        });
    }

    private function validarTransicion(string $actual, string $nuevo): void
    {
        if (! in_array($nuevo, self::ORDEN_ESTADOS, true)) {
            throw new OperacionInvalidaException(
                "El estado '{$nuevo}' no existe. Valores válidos: " . implode(', ', self::ORDEN_ESTADOS) . '.',
                422
            );
        }

        $indiceActual = array_search($actual, self::ORDEN_ESTADOS, true);
        $indiceNuevo = array_search($nuevo, self::ORDEN_ESTADOS, true);

        if ($indiceNuevo !== $indiceActual + 1) {
            $siguienteValido = self::ORDEN_ESTADOS[$indiceActual + 1] ?? null;
            $mensaje = $siguienteValido
                ? "No se puede pasar de '{$actual}' a '{$nuevo}' directamente. El único siguiente estado válido es '{$siguienteValido}'."
                : "La campaña ya está en su último estado ('{$actual}') y no puede avanzar más.";

            throw new OperacionInvalidaException($mensaje, 409);
        }
    }
}
