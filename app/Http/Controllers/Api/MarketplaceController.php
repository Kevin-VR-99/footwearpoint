<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MarketplaceDistribuidoraResource;
use App\Services\Directorio\DirectorioPublico;
use Illuminate\Http\JsonResponse;

class MarketplaceController extends Controller
{
    /**
     * Directorio público de distribuidoras (E15-01).
     * Sin autenticación. Solo activas y visibles en marketplace.
     */
    public function index(DirectorioPublico $directorio): JsonResponse
    {
        return response()->json([
            'data' => MarketplaceDistribuidoraResource::collection($directorio->distribuidoras()),
        ]);
    }

    /**
     * Las categorías del directorio ya no se usan. La ruta se conserva y
     * responde una lista vacía.
     */
    public function categorias(): JsonResponse
    {
        return response()->json(['data' => []]);
    }
}