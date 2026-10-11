<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CategoriaDirectorio;
use App\Models\Distribuidora;
use App\Services\Directorio\AsignarCategoriasDirectorioAction;
use App\Services\Directorio\CambiarEstadoCategoriaDirectorioAction;
use App\Services\Directorio\GuardarCategoriaDirectorioAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * TG-197 (G16) — Categorías generales del directorio (E2-05), solo admin
 * general. Las reglas y mensajes viven en las acciones de App\Services\Directorio,
 * que también usa el panel.
 */
class CategoriaDirectorioController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $categorias = CategoriaDirectorio::query()
            ->withCount('distribuidoras')
            ->when($request->has('activa'), fn ($q) => $q->where('activa', $request->boolean('activa')))
            ->orderBy('nombre')
            ->get();

        return response()->json([
            'data' => $categorias->map(fn (CategoriaDirectorio $c) => $this->datos($c))->values(),
        ]);
    }

    public function store(Request $request, GuardarCategoriaDirectorioAction $guardar): JsonResponse
    {
        $categoria = $guardar->ejecutar($request->only(['nombre', 'activa']));

        return response()->json([
            'data'    => $this->datos($categoria),
            'message' => 'Categoría creada correctamente.',
        ], 201);
    }

    public function update(Request $request, GuardarCategoriaDirectorioAction $guardar, int $id): JsonResponse
    {
        $categoria = $guardar->ejecutar($request->only(['nombre', 'activa']), CategoriaDirectorio::findOrFail($id));

        return response()->json([
            'data'    => $this->datos($categoria),
            'message' => 'Categoría actualizada correctamente.',
        ]);
    }

    public function activar(CambiarEstadoCategoriaDirectorioAction $estado, int $id): JsonResponse
    {
        $categoria = $estado->ejecutar(CategoriaDirectorio::findOrFail($id), true);

        return response()->json([
            'data'    => $this->datos($categoria),
            'message' => 'Categoría activada correctamente.',
        ]);
    }

    public function desactivar(CambiarEstadoCategoriaDirectorioAction $estado, int $id): JsonResponse
    {
        $categoria = $estado->ejecutar(CategoriaDirectorio::findOrFail($id), false);

        return response()->json([
            'data'    => $this->datos($categoria),
            'message' => 'Categoría desactivada correctamente.',
        ]);
    }

    /** Reemplaza las categorías activas de una distribuidora: { "categorias": [1, 3] }. */
    public function asignar(Request $request, AsignarCategoriasDirectorioAction $asignar, int $id): JsonResponse
    {
        $datos = $request->validate(
            [
                'categorias'   => ['present', 'array'],
                'categorias.*' => ['integer'],
            ],
            [
                'categorias.present' => 'Indica las categorías de la distribuidora (puede ser una lista vacía).',
                'categorias.array'   => 'Las categorías deben ir en una lista.',
                'categorias.*.integer' => AsignarCategoriasDirectorioAction::MENSAJE_NO_DISPONIBLE,
            ]
        );

        $distribuidora = Distribuidora::findOrFail($id);
        $categorias = $asignar->ejecutar($distribuidora, $datos['categorias']);

        return response()->json([
            'data' => [
                'id'               => $distribuidora->id,
                'nombre_comercial' => $distribuidora->nombre_comercial,
                'categorias'       => $categorias->map(fn (CategoriaDirectorio $c) => [
                    'id'     => $c->id,
                    'nombre' => $c->nombre,
                    'activa' => (bool) $c->activa,
                ])->values(),
            ],
            'message' => 'Categorías de la distribuidora guardadas correctamente.',
        ]);
    }

    private function datos(CategoriaDirectorio $categoria): array
    {
        return [
            'id'                  => $categoria->id,
            'nombre'              => $categoria->nombre,
            'activa'              => (bool) $categoria->activa,
            'total_distribuidoras' => (int) ($categoria->distribuidoras_count ?? $categoria->distribuidoras()->count()),
        ];
    }
}
