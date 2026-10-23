<?php

namespace App\Http\Controllers\Api\Catalogo;

use App\Http\Controllers\Controller;
use App\Http\Resources\Catalogo\CampanaResource;
use App\Models\Campana;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Solo lectura: cada campaña es la publicación de una línea en una temporada
 * global y se crea sola al importar su catálogo.
 */
class CampanaController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CampanaResource::collection(
            Campana::with(['linea', 'temporada'])->latest()->get()
        );
    }

    public function show(Campana $campana): CampanaResource
    {
        return new CampanaResource($campana->load(['linea', 'temporada']));
    }
}
