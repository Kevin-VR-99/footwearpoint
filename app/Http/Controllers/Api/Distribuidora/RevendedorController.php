<?php

namespace App\Http\Controllers\Api\Distribuidora;

use App\Http\Controllers\Controller;
use App\Http\Requests\Distribuidora\GuardarRevendedorRequest;
use App\Http\Resources\Distribuidora\RevendedorAfiliacionResource;
use App\Models\RevendedorDistribuidora;
use App\Services\Distribuidora\ActivarCuentaAccesoAction;
use App\Services\Distribuidora\GestionarRevendedorAction;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class RevendedorController extends Controller
{
    private const CAMPOS_ACCESO = ['acceso_email', 'acceso_password'];

    public function index(): AnonymousResourceCollection
    {
        // El Global Scope BelongsToTenant ya filtra por tu distribuidora;
        // el with('revendedor') trae los datos globales (nombre, tel, email).
        return RevendedorAfiliacionResource::collection(
            RevendedorDistribuidora::with('revendedor.usuario')->get()
        );
    }

    public function show(RevendedorDistribuidora $revendedor): RevendedorAfiliacionResource
    {
        return new RevendedorAfiliacionResource($revendedor->load('revendedor.usuario'));
    }

    public function store(
        GuardarRevendedorRequest $request,
        GestionarRevendedorAction $accion,
        ActivarCuentaAccesoAction $activarCuenta
    ): RevendedorAfiliacionResource {
        $datos = $request->validated();

        // Una sola transacción: si la cuenta falla (por ejemplo, un correo
        // repetido), tampoco se crea el revendedor. Así el admin corrige el
        // correo y vuelve a intentar sin dejar un duplicado.
        $afiliacion = DB::transaction(function () use ($datos, $accion, $activarCuenta) {
            $afiliacion = $accion->afiliar(Arr::except($datos, self::CAMPOS_ACCESO));
            $this->activarCuentaSiSeMando($afiliacion, $datos, $activarCuenta);

            return $afiliacion;
        });

        return new RevendedorAfiliacionResource($afiliacion->fresh('revendedor.usuario'));
    }

    /**
     * "Desafiliar" NO es un endpoint aparte: se hace con este mismo PATCH,
     * mandando "estado": "inactivo". Coincide con el patrón del resto del
     * proyecto (no hay borrados definitivos, solo cambios de estado).
     *
     * Aquí también se le puede activar la cuenta de acceso a un revendedor
     * que ya existía (E3-07), mandando acceso_email y acceso_password.
     */
    public function update(
        GuardarRevendedorRequest $request,
        RevendedorDistribuidora $revendedor,
        GestionarRevendedorAction $accion,
        ActivarCuentaAccesoAction $activarCuenta
    ): RevendedorAfiliacionResource {
        $datos = $request->validated();

        $afiliacion = DB::transaction(function () use ($datos, $revendedor, $accion, $activarCuenta) {
            $afiliacion = $accion->actualizar($revendedor, Arr::except($datos, self::CAMPOS_ACCESO));
            $this->activarCuentaSiSeMando($afiliacion, $datos, $activarCuenta);

            return $afiliacion;
        });

        return new RevendedorAfiliacionResource($afiliacion->fresh('revendedor.usuario'));
    }

    private function activarCuentaSiSeMando(
        RevendedorDistribuidora $afiliacion,
        array $datos,
        ActivarCuentaAccesoAction $activarCuenta
    ): void {
        if (empty($datos['acceso_email'])) {
            return;
        }

        $activarCuenta->paraRevendedor(
            $afiliacion->fresh('revendedor'),
            $datos['acceso_email'],
            $datos['acceso_password'],
        );
    }
}
