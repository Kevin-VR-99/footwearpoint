<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MarketplaceDistribuidoraResource;
use App\Models\Distribuidora;
use Illuminate\Http\JsonResponse;

class MarketplaceController extends Controller
{
    /**
     * Directorio público de distribuidoras (E15-01).
     * Sin autenticación. Solo activas y visibles en marketplace.
     */
    public function index(): JsonResponse
    {
        $distribuidoras = Distribuidora::query()
            ->where('estado', 'activa')
            ->where('marketplace_visible', true)
            ->orderBy('nombre_comercial')
            ->get();

        return response()->json([
            'data' => MarketplaceDistribuidoraResource::collection($distribuidoras),
        ]);
    }
}