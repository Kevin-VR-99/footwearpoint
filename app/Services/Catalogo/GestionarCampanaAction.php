<?php

namespace App\Services\Catalogo;

use App\Exceptions\OperacionInvalidaException;
use App\Models\Campana;

class GestionarCampanaAction
{
    /**
     * Orden real de los 6 estados, tal como está documentado en el modelo
     * v3. Solo se permite avanzar UN paso a la vez en este orden — no
     * saltar estados ni retroceder.
     */
    private const ORDEN_ESTADOS = [
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
        return Campana::create([
            'marca_id'     => $datos['marca_id'] ?? null,
            'nombre'       => $datos['nombre'],
            'descripcion'  => $datos['descripcion'] ?? null,
            'fecha_inicio' => $datos['fecha_inicio'] ?? null,
            'fecha_fin'    => $datos['fecha_fin'] ?? null,
            'estado'       => 'borrador',
        ]);
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

        // marca_id nunca se acepta aquí (el Form Request ya lo bloquea).
        $campana->fill($datos);
        $campana->save();

        return $campana->fresh();
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
