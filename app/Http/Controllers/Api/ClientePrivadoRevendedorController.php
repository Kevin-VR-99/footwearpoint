<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientePrivadoRevendedor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

        $validados = $this->validar($request, $revendedorId);

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

        $validados = $this->validar($request, $revendedorId, $cliente->id);

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

    /**
     * Misma validación al crear y al editar (TG-162, corrige lo de E9-06).
     *
     * Los límites son los de la tabla clientes_privados_revendedor. Antes se
     * permitía más de lo que cabe (nombre 190 vs 150, teléfono 50 vs 30,
     * referencia 190 vs 150) y un nombre repetido chocaba con la regla única
     * de la tabla: en todos esos casos la base rechazaba el dato y la app
     * recibía un error 500 en lugar de un mensaje.
     *
     * @param  int|null  $ignorarId  Al editar, el propio cliente, para que
     *                               guardarlo sin cambiarle el nombre no cuente
     *                               como repetido.
     */
    protected function validar(Request $request, int $revendedorId, ?int $ignorarId = null): array
    {
        $validados = $request->validate([
            'nombre' => [
                'required', 'string', 'max:150',
                // Único por revendedor, igual que el índice de la tabla:
                // dos revendedores sí pueden tener un cliente con el mismo nombre.
                Rule::unique('clientes_privados_revendedor', 'nombre')
                    ->where('revendedor_id', $revendedorId)
                    ->ignore($ignorarId),
            ],
            'telefono'   => ['nullable', 'string', 'max:30'],
            'producto'   => ['nullable', 'string', 'max:190'],
            // decimal(10,2): hasta 99,999,999.99. Sin negativos.
            'monto'      => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'saldo'      => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'referencia' => ['nullable', 'string', 'max:150'],
            'notas'      => ['nullable', 'string'],
        ], [
            'nombre.required'  => 'Escribe el nombre del cliente.',
            'nombre.max'       => 'El nombre no puede tener más de 150 caracteres.',
            'nombre.unique'    => 'Ya tienes un cliente con ese nombre.',
            'telefono.max'     => 'El teléfono no puede tener más de 30 caracteres.',
            'producto.max'     => 'El producto no puede tener más de 190 caracteres.',
            'monto.min'        => 'El monto no puede ser negativo.',
            'monto.max'        => 'El monto es demasiado grande.',
            'saldo.min'        => 'El saldo no puede ser negativo.',
            'saldo.max'        => 'El saldo es demasiado grande.',
            'referencia.max'   => 'La referencia no puede tener más de 150 caracteres.',
        ]);

        // En la tabla, monto y saldo no aceptan vacío (su valor por defecto es
        // 0). Si llegan vacíos se guardan como 0 en vez de reventar.
        foreach (['monto', 'saldo'] as $campo) {
            if (array_key_exists($campo, $validados) && $validados[$campo] === null) {
                $validados[$campo] = 0;
            }
        }

        return $validados;
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