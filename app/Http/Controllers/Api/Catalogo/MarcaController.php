<?php

namespace App\Http\Controllers\Api\Catalogo;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogo\GuardarMarcaRequest;
use App\Http\Resources\Catalogo\MarcaResource;
use App\Models\Marca;
use App\Services\Catalogo\GestionarMarcaAction;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MarcaController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return MarcaResource::collection(Marca::all());
    }

    public function show(Marca $marca): MarcaResource
    {
        return new MarcaResource($marca);
    }

    public function store(GuardarMarcaRequest $request, GestionarMarcaAction $accion): MarcaResource
    {
        $marca = $accion->crear(
            $request->safe()->except('logotipo'),
            $request->file('logotipo')
        );

        return new MarcaResource($marca);
    }

    public function update(GuardarMarcaRequest $request, Marca $marca, GestionarMarcaAction $accion): MarcaResource
    {
        $marca = $accion->actualizar(
            $marca,
            $request->safe()->except('logotipo'),
            $request->file('logotipo')
        );

        return new MarcaResource($marca);
    }
}
