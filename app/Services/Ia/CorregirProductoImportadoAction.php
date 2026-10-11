<?php

namespace App\Services\Ia;

use App\Models\ProductoImportadoStaging;
use Illuminate\Support\Facades\Auth;

/**
 * TG-239 (A9 / E5-03) — Guardar la corrección de una persona.
 *
 * Lo importante: un campo que la persona TOCÓ deja de ser dudoso. La IA lo
 * marcó porque no estaba segura; en cuanto alguien lo revisó, ya hay un ser
 * humano respondiendo por ese dato y no tiene caso seguir resaltándolo.
 *
 * Los campos que no se tocaron conservan su marca, para que quien siga
 * revisando sepa qué falta mirar.
 */
class CorregirProductoImportadoAction
{
    /**
     * @param  array<string, mixed>  $datos  Solo los campos que vienen del formulario.
     */
    public function ejecutar(ProductoImportadoStaging $fila, array $datos): void
    {
        $antes = $fila->datos_extraidos ?? [];
        $dudosos = $fila->campos_dudosos ?? [];

        $despues = $antes;

        foreach (CamposDelProductoImportado::todos() as $campo) {
            if (! array_key_exists($campo, $datos)) {
                continue;
            }

            $valor = $this->normalizar($campo, $datos[$campo]);

            // Se compara contra el valor anterior TAMBIÉN normalizado. Si no,
            // un precio que la base devuelve como 699 y el formulario como
            // 699.0 parecería un cambio, y se daría por revisado un campo que
            // nadie miró, que es justo lo contrario de lo que queremos.
            $anterior = $this->normalizar($campo, $antes[$campo] ?? null);

            // Solo cuenta como revisado lo que de verdad cambió de valor.
            if ($valor !== $anterior) {
                $despues[$campo] = $valor;
                $dudosos = array_values(array_diff($dudosos, [$campo]));
            }
        }

        $fila->update([
            'datos_extraidos' => $despues,
            'campos_dudosos'  => $dudosos,
            'estado'          => 'corregido',
        ]);

        $this->anotarQuienRevisa($fila);
    }

    /**
     * Marca quién está revisando el catálogo, la primera vez que toca algo.
     * Sirve para la bitácora y para saber a quién preguntarle después.
     */
    public function anotarQuienRevisa(ProductoImportadoStaging $fila): void
    {
        $importacion = $fila->importacion;

        if ($importacion !== null && $importacion->revisada_por_usuario_id === null) {
            $importacion->update(['revisada_por_usuario_id' => Auth::id()]);
        }
    }

    /** Lo que escribe una persona llega como texto; aquí se deja del tipo que toca. */
    private function normalizar(string $campo, mixed $valor): mixed
    {
        if (is_string($valor)) {
            $valor = trim($valor);

            if ($valor === '') {
                return null;
            }
        }

        return match ($campo) {
            'pagina', 'categoria_id' => $valor === null ? null : (int) $valor,
            'precio_publico' => $valor === null ? null : round((float) $valor, 2),
            'medios_numeros' => $valor === null ? null : filter_var($valor, FILTER_VALIDATE_BOOLEAN),
            default          => $valor,
        };
    }
}
