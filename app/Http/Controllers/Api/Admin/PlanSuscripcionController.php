<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlanSuscripcionRequest;
use App\Models\PlanSuscripcion;
use Illuminate\Http\Request;

class PlanSuscripcionController extends Controller
{
    public function index(Request $request)
    {
        $query = PlanSuscripcion::query()->orderBy('precio_base_mensual');

        if ($request->has('activo')) {
            $query->where('activo', filter_var($request->activo, FILTER_VALIDATE_BOOLEAN));
        }

        return response()->json([
            'data' => $query->get(),
        ]);
    }

    public function show(int $id)
    {
        $plan = PlanSuscripcion::findOrFail($id);

        return response()->json([
            'data' => $plan,
        ]);
    }

    public function store(PlanSuscripcionRequest $request)
    {
        $plan = PlanSuscripcion::create([
            'nombre'              => $request->nombre,
            'descripcion'         => $request->descripcion,
            'precio_base_mensual' => $request->precio_base_mensual,
            'lineas_incluidas'    => $request->lineas_incluidas,
            'precio_linea_extra'  => $request->precio_linea_extra,
            'activo'              => $request->boolean('activo', true),
        ]);

        return response()->json([
            'data'    => $plan,
            'message' => 'Plan creado correctamente.',
        ], 201);
    }

    public function update(PlanSuscripcionRequest $request, int $id)
    {
        $plan = PlanSuscripcion::findOrFail($id);

        $plan->update([
            'nombre'              => $request->nombre,
            'descripcion'         => $request->descripcion,
            'precio_base_mensual' => $request->precio_base_mensual,
            'lineas_incluidas'    => $request->lineas_incluidas,
            'precio_linea_extra'  => $request->precio_linea_extra,
            'activo'              => $request->has('activo')
                ? $request->boolean('activo')
                : $plan->activo,
        ]);

        return response()->json([
            'data'    => $plan->fresh(),
            'message' => 'Plan actualizado correctamente.',
        ]);
    }

    public function destroy(int $id)
    {
        $plan = PlanSuscripcion::findOrFail($id);

        // No se borra: se desactiva (mejor para historial de suscripciones)
        $plan->update(['activo' => false]);

        return response()->json([
            'data'    => $plan,
            'message' => 'Plan desactivado correctamente.',
        ]);
    }
}