<?php

namespace App\Http\Controllers\Api\Distribuidora;

use App\Http\Controllers\Controller;
use App\Http\Requests\Distribuidora\GuardarClienteDirectoRequest;
use App\Http\Resources\Distribuidora\ClienteDirectoResource;
use App\Models\ClienteDirecto;
use App\Services\Distribuidora\GestionarClienteDirectoAction;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ClienteDirectoController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ClienteDirectoResource::collection(ClienteDirecto::all());
    }

    public function show(ClienteDirecto $cliente): ClienteDirectoResource
    {
        return new ClienteDirectoResource($cliente);
    }

    public function store(
        GuardarClienteDirectoRequest $request,
        GestionarClienteDirectoAction $accion
    ): ClienteDirectoResource {
        $cliente = $accion->crear($request->validated());

        return new ClienteDirectoResource($cliente);
    }

    public function update(
        GuardarClienteDirectoRequest $request,
        ClienteDirecto $cliente,
        GestionarClienteDirectoAction $accion
    ): ClienteDirectoResource {
        $cliente = $accion->actualizar($cliente, $request->validated());

        return new ClienteDirectoResource($cliente);
    }
}
