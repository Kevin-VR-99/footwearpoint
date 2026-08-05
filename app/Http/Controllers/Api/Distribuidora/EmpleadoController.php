<?php

namespace App\Http\Controllers\Api\Distribuidora;

use App\Http\Controllers\Controller;
use App\Http\Requests\Distribuidora\ActualizarEmpleadoRequest;
use App\Http\Resources\Distribuidora\EmpleadoResource;
use App\Models\DistribuidoraStaff;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmpleadoController extends Controller
{
    /**
     * Alta de empleado (con cuenta y contraseña) NO vive aquí — ver
     * POST /api/auth/register-empleado (Paquete A). Este controlador solo
     * lista y edita el estado de empleados que YA existen.
     */
    public function index(): AnonymousResourceCollection
    {
        return EmpleadoResource::collection(
            DistribuidoraStaff::with('usuario')->where('tipo', 'empleado')->get()
        );
    }

    public function show(DistribuidoraStaff $empleado): EmpleadoResource
    {
        return new EmpleadoResource($empleado->load('usuario'));
    }

    public function update(ActualizarEmpleadoRequest $request, DistribuidoraStaff $empleado): EmpleadoResource
    {
        $empleado->estado = $request->validated('estado');
        $empleado->save();

        return new EmpleadoResource($empleado->load('usuario'));
    }
}
