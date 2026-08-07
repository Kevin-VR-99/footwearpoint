<?php

namespace App\Services\Catalogo;

use App\Exceptions\OperacionInvalidaException;
use App\Models\Linea;
use App\Models\Suscripcion;
use App\Support\Tenant;

class GestionarLineaAction
{
    public function crear(array $datos, array $marcaIds = []): Linea
    {
        $this->validarLimiteDeLineas();

        $linea = Linea::create([
            'campana_id'  => $datos['campana_id'],
            'nombre'      => $datos['nombre'],
            'descripcion' => $datos['descripcion'] ?? null,
            'activa'      => true,
        ]);

        if (! empty($marcaIds)) {
            $this->sincronizarMarcas($linea, $marcaIds);
        }

        return $linea->load('marcas', 'campana');
    }

    public function actualizar(Linea $linea, array $datos, ?array $marcaIds = null): Linea
    {
        $reactivando = ($datos['activa'] ?? null) === true && $linea->activa === false;

        if ($reactivando) {
            $this->validarLimiteDeLineas();
        }

        $linea->fill([
            'nombre'      => $datos['nombre'] ?? $linea->nombre,
            'descripcion' => array_key_exists('descripcion', $datos) ? $datos['descripcion'] : $linea->descripcion,
            'activa'      => array_key_exists('activa', $datos) ? $datos['activa'] : $linea->activa,
        ]);
        $linea->save();

        if (is_array($marcaIds)) {
            $this->sincronizarMarcas($linea, $marcaIds);
        }

        return $linea->fresh(['marcas', 'campana']);
    }

    private function sincronizarMarcas(Linea $linea, array $marcaIds): void
    {
        $sync = [];
        foreach ($marcaIds as $marcaId) {
            $sync[(int) $marcaId] = ['distribuidora_id' => Tenant::id()];
        }
        $linea->marcas()->sync($sync);
    }

    private function validarLimiteDeLineas(): void
    {
        $suscripcion = Suscripcion::where('estado', 'activa')->first();

        if (! $suscripcion) {
            throw new OperacionInvalidaException(
                'No se encontró una suscripción activa; no se puede validar el límite de líneas.',
                409
            );
        }

        $limite = $suscripcion->lineas_incluidas_contratadas + $suscripcion->lineas_extra_contratadas;
        $lineasActivas = Linea::where('activa', true)->count();

        if ($lineasActivas >= $limite) {
            throw new OperacionInvalidaException(
                "Ya alcanzaste el límite de {$limite} línea(s) activa(s) de tu plan actual. Contacta al administrador general para ampliar tu plan.",
                409
            );
        }
    }
}