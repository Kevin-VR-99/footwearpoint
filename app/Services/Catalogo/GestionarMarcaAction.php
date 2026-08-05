<?php

namespace App\Services\Catalogo;

use App\Exceptions\OperacionInvalidaException;
use App\Models\Marca;
use App\Models\Suscripcion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class GestionarMarcaAction
{
    public function crear(array $datos, ?UploadedFile $logo = null): Marca
    {
        $this->validarLimiteDeLineas();

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

    /**
     * Si la edición reactiva una marca antes inactiva, vuelve a consumir un
     * "cupo" de línea — por eso aquí también se valida el límite. No viene
     * escrito así en ningún documento; es una consecuencia lógica mía de la
     * regla ya confirmada (bloquear al exceder el límite). Avísalo al
     * equipo si prefieren que reactivar NUNCA cuente contra el límite.
     */
    public function actualizar(Marca $marca, array $datos, ?UploadedFile $logo = null): Marca
    {
        $reactivando = ($datos['activa'] ?? null) === true && $marca->activa === false;

        if ($reactivando) {
            $this->validarLimiteDeLineas();
        }

        if ($logo) {
            $datos['logotipo_url'] = $this->subirLogo($logo);
        }

        $marca->fill($datos);
        $marca->save();

        return $marca->fresh();
    }

    /**
     * DECISIÓN PROVISIONAL MÍA: si la distribuidora no tiene ninguna
     * suscripción con estado='activa' (no debería pasar en la práctica,
     * pero no hay ninguna restricción en la base de datos que lo impida),
     * bloqueo la creación por completo — no hay forma de calcular un
     * límite sin un plan vigente. Avisa al equipo si prefieren otro
     * comportamiento para ese caso límite.
     */
    private function validarLimiteDeLineas(): void
    {
        $suscripcion = Suscripcion::where('estado', 'activa')->first();

        if (! $suscripcion) {
            throw new OperacionInvalidaException(
                'No se encontró una suscripción activa para esta distribuidora; no se puede validar el límite de marcas.',
                409
            );
        }

        $limite = $suscripcion->lineas_incluidas_contratadas + $suscripcion->lineas_extra_contratadas;
        $marcasActivas = Marca::where('activa', true)->count();

        if ($marcasActivas >= $limite) {
            throw new OperacionInvalidaException(
                "Ya alcanzaste el límite de {$limite} marca(s) activa(s) de tu plan actual. Contacta al administrador general para ampliar tu plan.",
                409
            );
        }
    }

    private function subirLogo(UploadedFile $logo): string
    {
        $ruta = $logo->store('marcas/logotipos', 's3');

        return Storage::disk('s3')->url($ruta);
    }
}
