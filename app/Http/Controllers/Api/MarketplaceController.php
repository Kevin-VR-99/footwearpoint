<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MarketplaceDistribuidoraResource;
use App\Services\Directorio\DirectorioPublico;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketplaceController extends Controller
{
    /**
     * Directorio público de distribuidoras (E15-01).
     * Sin autenticación. Solo activas y visibles en marketplace.
     *
     * TG-197 (G16): ?categoria={id} filtra por una categoría del directorio
     * (solo cuentan las activas) y cada distribuidora trae sus categorías.
     */
    public function index(Request $request, DirectorioPublico $directorio): JsonResponse
    {
        $datos = $request->validate(
            ['categoria' => ['nullable', 'integer', 'min:1']],
            [
                'categoria.integer' => 'La categoría no es válida.',
                'categoria.min'     => 'La categoría no es válida.',
            ]
        );

        $categoria = isset($datos['categoria']) ? (int) $datos['categoria'] : null;

        return response()->json([
            'data' => MarketplaceDistribuidoraResource::collection($directorio->distribuidoras($categoria)),
        ]);
    }

    /**
     * TG-197 (G16) — Categorías para filtrar el directorio: activas y con al
     * menos una distribuidora visible, en orden alfabético. Sin autenticación.
     */
    public function categorias(DirectorioPublico $directorio): JsonResponse
    {
        return response()->json([
            'data' => $directorio->categorias()->map(fn ($categoria) => [
                'id'                   => $categoria->id,
                'nombre'               => $categoria->nombre,
                'total_distribuidoras' => (int) $categoria->total_distribuidoras,
            ])->values(),
        ]);
    }
}