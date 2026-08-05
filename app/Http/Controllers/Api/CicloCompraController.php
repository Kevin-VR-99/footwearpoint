<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CicloCompraResource;
use App\Models\CicloCompra;
use App\Services\Ciclo\AsignarCicloVigenteService;
use App\Services\Ciclo\ResultadoCiclo;
use App\Services\Ciclo\TransicionCicloService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * E10 - Ciclos de compra.
 *
 * Ningun endpoint de aqui recibe cuerpo: la transicion la determina la ruta,
 * no un campo que mande el cliente. Por eso no hay Form Requests (la
 * convencion 1.6 pide uno "por cada endpoint que reciba datos").
 */
class CicloCompraController extends Controller
{
    public function __construct(private TransicionCicloService $ciclos)
    {
    }

    /**
     * Punto de entrada de la pantalla "Ciclo de Compra Actual": el frontend
     * no tiene otra forma de conocer el id del ciclo vigente.
     *
     * OJO: si la distribuidora todavia no tiene ciclo abierto, esta llamada
     * lo crea (es el mismo servicio que usa el Paquete D al confirmar un
     * pedido). Es un GET con efecto de escritura; se hizo asi porque el
     * lider pidio exponer AsignarCicloVigente tal cual. Si el equipo
     * prefiere semantica estricta, se cambia la ruta a POST y ya.
     */
    public function vigente(AsignarCicloVigenteService $asignar): JsonResponse
    {
        Gate::authorize('view', CicloCompra::class);

        $ciclo = $asignar->paraDistribuidoraActual();

        return $this->responder(
            $this->ciclos->ver($ciclo->id),
            'Ciclo vigente de la distribuidora.'
        );
    }

    public function show(int $id): JsonResponse
    {
        Gate::authorize('view', CicloCompra::class);

        return $this->responder($this->ciclos->ver($id), 'Ciclo de compra.');
    }

    public function cerrar(int $id): JsonResponse
    {
        Gate::authorize('update', CicloCompra::class);

        return $this->responder(
            $this->ciclos->cerrar($id),
            'Ciclo cerrado: ya no acepta pedidos nuevos.'
        );
    }

    public function solicitarFabrica(int $id): JsonResponse
    {
        Gate::authorize('update', CicloCompra::class);

        return $this->responder(
            $this->ciclos->solicitarFabrica($id),
            'Ciclo solicitado a fabrica.'
        );
    }

    public function marcarTransito(int $id): JsonResponse
    {
        Gate::authorize('update', CicloCompra::class);

        return $this->responder(
            $this->ciclos->marcarTransito($id),
            'Ciclo marcado en transito.'
        );
    }

    public function marcarRecibido(int $id): JsonResponse
    {
        Gate::authorize('update', CicloCompra::class);

        return $this->responder(
            $this->ciclos->marcarRecibido($id),
            'Ciclo recibido en la distribuidora.'
        );
    }

    public function finalizar(int $id): JsonResponse
    {
        Gate::authorize('update', CicloCompra::class);

        return $this->responder(
            $this->ciclos->finalizar($id),
            'Ciclo finalizado.'
        );
    }

    private function responder(ResultadoCiclo $resultado, string $mensaje): JsonResponse
    {
        return (new CicloCompraResource($resultado))
            ->additional(['message' => $mensaje])
            ->response();
    }
}