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
            $request->filled('vale_folio') ? $request->string('vale_folio')->toString() : null,
        );

        return (new VentaDirectaResource($resultado))
            ->additional(['message' => 'Venta directa registrada.'])
            ->response()
            ->setStatusCode(201);
    }

    /** Cancela una venta de mostrador: stock de regreso, devolución del dinero y bitácora. */
    public function anular(\Illuminate\Http\Request $request, int $id, \App\Services\VentaDirecta\AnularVentaDirectaAction $accion): JsonResponse
    {
        Gate::authorize('create', VentaDirecta::class);

        $request->validate(['motivo' => ['required', 'string', 'min:5', 'max:300']]);

        $venta = $accion->ejecutar($id, $request->string('motivo')->toString());

        return response()->json([
            'data' => [
                'id'               => $venta->id,
                'folio'            => $venta->folio,
                'estado'           => $venta->estado,
                'anulada_at'       => $venta->anulada_at?->toIso8601String(),
                'motivo_anulacion' => $venta->motivo_anulacion,
                'reembolsos'       => $venta->pagos->where('direccion', 'salida')->values()->map(fn ($p) => [
                    'folio' => $p->folio, 'metodo' => $p->metodo, 'monto' => (float) $p->monto,
                ]),
            ],
            'message' => 'Venta cancelada.',
        ]);
    }
}
