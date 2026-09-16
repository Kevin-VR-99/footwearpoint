<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientePrivadoRevendedor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientePrivadoRevendedorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $revendedorId = $this->obtenerRevendedorId($request);

        if (!$revendedorId) {
            return response()->json(['message' => 'No se encontró el perfil de revendedor.'], 403);
        }

        $clientes = ClientePrivadoRevendedor::where('revendedor_id', $revendedorId)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $clientes,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $revendedorId = $this->obtenerRevendedorId($request);

        if (!$revendedorId) {
            return response()->json(['message' => 'No se encontró el perfil de revendedor.'], 403);
        }

        $validados = $request->validate([
            'nombre'     => ['required', 'string', 'max:190'],
            'telefono'   => ['nullable', 'string', 'max:50'],
            'producto'   => ['nullable', 'string', 'max:190'],
            'monto'      => ['nullable', 'numeric'],
            'saldo'      => ['nullable', 'numeric'],
            'referencia' => ['nullable', 'string', 'max:190'],
            'notas'      => ['nullable', 'string'],
        ]);

        $validados['revendedor_id'] = $revendedorId;

        $cliente = ClientePrivadoRevendedor::create($validados);

        return response()->json([
            'data'    => $cliente,
            'message' => 'Cliente privado registrado correctamente.',
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $revendedorId = $this->obtenerRevendedorId($request);

        if (!$revendedorId) {
            return response()->json(['message' => 'No se encontró el perfil de revendedor.'], 403);
        }

        $cliente = ClientePrivadoRevendedor::where('revendedor_id', $revendedorId)
            ->findOrFail($id);

        $validados = $request->validate([
            'nombre'     => ['required', 'string', 'max:190'],
            'telefono'   => ['nullable', 'string', 'max:50'],
            'producto'   => ['nullable', 'string', 'max:190'],
            'monto'      => ['nullable', 'numeric'],
            'saldo'      => ['nullable', 'numeric'],
            'referencia' => ['nullable', 'string', 'max:190'],
            'notas'      => ['nullable', 'string'],
        ]);

        $cliente->update($validados);

        return response()->json([
            'data'    => $cliente,
            'message' => 'Cliente privado actualizado correctamente.',
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $revendedorId = $this->obtenerRevendedorId($request);

        if (!$revendedorId) {
            return response()->json(['message' => 'No se encontró el perfil de revendedor.'], 403);
        }

        $cliente = ClientePrivadoRevendedor::where('revendedor_id', $revendedorId)
            ->findOrFail($id);

        $cliente->delete();

        return response()->json([
            'message' => 'Cliente privado eliminado correctamente.',
        ]);
    }

    protected function obtenerRevendedorId(Request $request): ?int
    {
        $usuario = $request->user();
        if (!$usuario) {
            return null;
        }

        $revendedor = $usuario->revendedor()->first() ?? $usuario->revendedor;

        return $revendedor?->id;
    }
}