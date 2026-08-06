<?php

namespace App\Services\Pedido;

use App\Models\ClienteDirecto;
use App\Models\DistribuidoraStaff;
use App\Models\Pedido;
use App\Models\RevendedorDistribuidora;
use App\Models\Sucursal;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CrearPedidoBorradorAction
{
    public function ejecutar(array $datos): Pedido
    {
        $distribuidoraId = Tenant::id();
        abort_if($distribuidoraId === null, 403, 'No se pudo determinar la distribuidora.');

        $staffId = $this->staffIdActual();
        abort_if($staffId === null, 403, 'No se pudo determinar el staff del usuario autenticado.');

        $sucursal = Sucursal::where('id', $datos['sucursal_id'])->first();
        if (! $sucursal) {
            throw ValidationException::withMessages([
                'sucursal_id' => ['La sucursal no existe en esta distribuidora.'],
            ]);
        }

        $clienteId = null;
        $revendedorAfiliacionId = null;

        if ($datos['tipo'] === 'cliente_directo') {
            $cliente = ClienteDirecto::where('id', $datos['propietario_id'])->first();
            if (! $cliente) {
                throw ValidationException::withMessages([
                    'propietario_id' => ['El cliente directo no existe en esta distribuidora.'],
                ]);
            }
            $clienteId = $cliente->id;
        } else {
            $afiliacion = RevendedorDistribuidora::where('id', $datos['propietario_id'])->first();
            if (! $afiliacion) {
                throw ValidationException::withMessages([
                    'propietario_id' => ['La afiliación de revendedor no existe en esta distribuidora.'],
                ]);
            }
            $revendedorAfiliacionId = $afiliacion->id;
        }

        $folio = $this->generarFolio($distribuidoraId);

        $pedido = Pedido::create([
            'distribuidora_id'            => $distribuidoraId,
            'sucursal_id'                 => $sucursal->id,
            'folio'                       => $folio,
            'tipo'                        => $datos['tipo'],
            'cliente_directo_id'          => $clienteId,
            'revendedor_distribuidora_id' => $revendedorAfiliacionId,
            'ciclo_compra_id'             => null,
            'estado'                      => 'borrador',
            'subtotal'                    => 0,
            'total'                       => 0,
            'fecha_colocacion'            => null,
            'capturado_por_staff_id'      => $staffId,
            'observaciones'               => $datos['observaciones'] ?? null,
        ]);

        return $pedido->fresh(['clienteDirecto', 'revendedorAfiliacion.revendedor', 'detalle']);
    }

    protected function generarFolio(int $distribuidoraId): string
    {
        $prefijo = 'PED-' . now()->format('Ymd') . '-';

        $ultimo = Pedido::withoutGlobalScopes()
            ->where('distribuidora_id', $distribuidoraId)
            ->where('folio', 'like', $prefijo . '%')
            ->orderByDesc('id')
            ->value('folio');

        $secuencia = 1;
        if ($ultimo && preg_match('/-(\d+)$/', $ultimo, $m)) {
            $secuencia = (int) $m[1] + 1;
        }

        return $prefijo . str_pad((string) $secuencia, 4, '0', STR_PAD_LEFT);
    }

    protected function staffIdActual(): ?int
    {
        $usuario = Auth::user();
        if (! $usuario) {
            return null;
        }

        return DistribuidoraStaff::where('usuario_id', $usuario->id)
            ->where('estado', 'activo')
            ->value('id');
    }
}