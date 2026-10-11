<?php

namespace App\Services\Directorio;

use App\Models\CategoriaDirectorio;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * TG-197 (G16) — Crear o renombrar una categoría del directorio.
 *
 * Es la única lógica para esto: la usan el panel del admin general y
 * POST/PUT /api/admin/categorias-directorio. La validación vive aquí para
 * que los dos den los mismos mensajes.
 *
 * El nombre es único sin importar mayúsculas ni acentos ("Dama", "dama" y
 * "Dáma" son el mismo), igual que el índice único de la tabla.
 */
class GuardarCategoriaDirectorioAction
{
    public const MENSAJE_NOMBRE_OBLIGATORIO = 'Escribe el nombre de la categoría.';

    public const MENSAJE_NOMBRE_LARGO = 'El nombre no puede pasar de 120 caracteres.';

    public const MENSAJE_NOMBRE_REPETIDO = 'Ya existe una categoría con ese nombre.';

    /**
     * @param  array{nombre?: mixed, activa?: mixed}  $datos
     *
     * @throws ValidationException con los mensajes en el campo 'nombre'.
     */
    public function ejecutar(array $datos, ?CategoriaDirectorio $categoria = null): CategoriaDirectorio
    {
        $nombre = self::normalizarNombre($datos['nombre'] ?? null);

        Validator::make(
            ['nombre' => $nombre, 'activa' => $datos['activa'] ?? null],
            [
                'nombre' => [
                    'required',
                    'string',
                    'max:' . CategoriaDirectorio::LARGO_MAXIMO_NOMBRE,
                    Rule::unique('categorias_directorio', 'nombre')->ignore($categoria?->id),
                ],
                'activa' => ['nullable', 'boolean'],
            ],
            [
                'nombre.required' => self::MENSAJE_NOMBRE_OBLIGATORIO,
                'nombre.string'   => self::MENSAJE_NOMBRE_OBLIGATORIO,
                'nombre.max'      => self::MENSAJE_NOMBRE_LARGO,
                'nombre.unique'   => self::MENSAJE_NOMBRE_REPETIDO,
                'activa.boolean'  => 'Indica si la categoría está activa.',
            ]
        )->validate();

        $valores = ['nombre' => $nombre];

        if (array_key_exists('activa', $datos) && $datos['activa'] !== null) {
            $valores['activa'] = filter_var($datos['activa'], FILTER_VALIDATE_BOOLEAN);
        }

        try {
            if ($categoria === null) {
                return CategoriaDirectorio::create($valores + ['activa' => true]);
            }

            $categoria->update($valores);

            return $categoria->fresh();
        } catch (QueryException $e) {
            // Dos admins guardando el mismo nombre a la vez: el índice único
            // frena al segundo. Se le responde igual que a la validación.
            if (($e->errorInfo[0] ?? null) === '23000') {
                throw ValidationException::withMessages(['nombre' => [self::MENSAJE_NOMBRE_REPETIDO]]);
            }

            throw $e;
        }
    }

    /** Quita espacios de más: "  Calzado   infantil " -> "Calzado infantil". */
    public static function normalizarNombre(mixed $nombre): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $nombre));
    }
}
