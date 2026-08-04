<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\VentaDirecta\StoreVentaDirectaRequest;
use App\Http\Resources\VentaDirectaResource;
use App\Models\VentaDirecta;
use App\Services\VentaDirecta\RegistrarVentaDirectaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class VentaDirectaController extends Controller
{
    public function __construct(private RegistrarVentaDirectaService $ventas)
    {
    }

    public function store(StoreVentaDirectaRequest $request): JsonResponse
    {
        Gate::authorize('create', VentaDirecta::class);

        $resultado = $this->ventas->registrar(
            $request->input('lineas'),
            $request->string('metodo_pago')->toString(),
            $request->filled('cliente_directo_id') ? $request->integer('cliente_directo_id') : null,
        );

        return (new VentaDirectaResource($resultado))
            ->additional(['message' => 'Venta directa registrada.'])
            ->response()
            ->setStatusCode(201);
    }
}