<?php

namespace App\Http\Controllers\Api\Distribuidora;

use App\Http\Controllers\Controller;
use App\Http\Requests\Distribuidora\GuardarClienteDirectoRequest;
use App\Http\Resources\Distribuidora\ClienteDirectoResource;
use App\Models\ClienteDirecto;
use App\Services\Distribuidora\ActivarCuentaAccesoAction;
use App\Services\Distribuidora\GestionarClienteDirectoAction;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ClienteDirectoController extends Controller
{
    private const CAMPOS_ACCESO = ['acceso_email', 'acceso_password'];

    public function index(): AnonymousResourceCollection
    {
        return ClienteDirectoResource::collection(ClienteDirecto::with('usuario')->get());
    }

    public function show(ClienteDirecto $cliente): ClienteDirectoResource
    {
        return new ClienteDirectoResource($cliente->load('usuario'));
    }

    public function store(
        GuardarClienteDirectoRequest $request,
        GestionarClienteDirectoAction $accion,
        ActivarCuentaAccesoAction $activarCuenta
    ): ClienteDirectoResource {
        $datos = $request->validated();

        // Una sola transacción: si la cuenta falla (por ejemplo, un correo
        // repetido), tampoco se crea el cliente. Así el admin corrige el
        // correo y vuelve a intentar sin dejar un duplicado.
        $cliente = DB::transaction(function () use ($datos, $accion, $activarCuenta) {
            $cliente = $accion->crear(Arr::except($datos, self::CAMPOS_ACCESO));
            $this->activarCuentaSiSeMando($cliente, $datos, $activarCuenta);

            return $cliente;
        });

        return new ClienteDirectoResource($cliente->fresh('usuario'));
    }

    /**
     * Aquí también se le puede activar la cuenta de acceso a un cliente que
     * ya existía (E3-07), mandando acceso_email y acceso_password.
     */
    public function update(
        GuardarClienteDirectoRequest $request,
        ClienteDirecto $cliente,
        GestionarClienteDirectoAction $accion,
        ActivarCuentaAccesoAction $activarCuenta
    ): ClienteDirectoResource {
        $datos = $request->validated();

        $cliente = DB::transaction(function () use ($datos, $cliente, $accion, $activarCuenta) {
            $cliente = $accion->actualizar($cliente, Arr::except($datos, self::CAMPOS_ACCESO));
            $this->activarCuentaSiSeMando($cliente, $datos, $activarCuenta);

            return $cliente;
        });

        return new ClienteDirectoResource($cliente->fresh('usuario'));
    }

    private function activarCuentaSiSeMando(
        ClienteDirecto $cliente,
        array $datos,
        ActivarCuentaAccesoAction $activarCuenta
    ): void {
        if (empty($datos['acceso_email'])) {
            return;
        }

        $activarCuenta->paraClienteDirecto(
            $cliente->fresh(),
            $datos['acceso_email'],
            $datos['acceso_password'],
        );
    }
}
