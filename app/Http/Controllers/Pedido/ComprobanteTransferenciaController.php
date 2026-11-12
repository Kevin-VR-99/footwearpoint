<?php

namespace App\Http\Controllers\Pedido;

use App\Http\Controllers\Controller;
use App\Models\ComprobanteTransferencia;
use App\Models\Pedido;
use App\Services\Pedido\SubirComprobanteTransferenciaAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * El archivo lo ven la distribuidora del pedido y su cliente.
 * No es público y subirlo no cuenta como pago.
 */
class ComprobanteTransferenciaController extends Controller
{
    public function subir(Request $request, int $id, SubirComprobanteTransferenciaAction $accion): JsonResponse
    {
        abort_if(Tenant::id() === null, 403, 'No se pudo determinar la distribuidora.');

        $pedido = $this->pedido($id);
        $archivo = $request->file('comprobante');

        abort_if($archivo === null, 422, 'Elige el archivo del comprobante.');

        $comprobante = $accion->ejecutar($pedido, $archivo);

        return response()->json([
            'data' => $this->datos($comprobante),
            'message' => 'Comprobante recibido. El pago cuenta cuando la distribuidora lo confirma.',
        ], 201);
    }

    public function ver(int $id): StreamedResponse
    {
        abort_if(Tenant::id() === null, 403, 'No se pudo determinar la distribuidora.');

        $pedido = $this->pedido($id);
        $comprobante = ComprobanteTransferencia::query()->where('pedido_id', $pedido->id)->first();

        abort_if($comprobante === null || ! Storage::disk($comprobante->disco)->exists($comprobante->ruta), 404);

        return Storage::disk($comprobante->disco)->download($comprobante->ruta, $comprobante->nombre_original, [
            'Content-Type' => $comprobante->mime,
        ]);
    }

    private function pedido(int $id): Pedido
    {
        return PropietarioActual::limitar(Pedido::query())->findOrFail($id);
    }

    /** @return array{nombre: string, tamano: int} */
    private function datos(ComprobanteTransferencia $comprobante): array
    {
        return [
            'nombre' => $comprobante->nombre_original,
            'tamano' => (int) $comprobante->tamano,
        ];
    }
}
