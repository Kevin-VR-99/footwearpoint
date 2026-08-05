<?php

namespace App\Services\Vale;

use App\Models\ClienteDirecto;
use App\Models\ConfiguracionDistribuidora;
use App\Models\DistribuidoraStaff;
use App\Models\RevendedorDistribuidora;
use App\Models\Vale;
use App\Models\ValeMovimiento;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmitirValeAction
{
    public function ejecutar(array $datos): Vale
    {
        $distribuidoraId = Tenant::id();
        abort_if($distribuidoraId === null, 403, 'No se pudo determinar la distribuidora.');

        $staffId = $this->staffIdActual();
        abort_if($staffId === null, 403, 'No se pudo determinar el staff del usuario autenticado.');

        $clienteId = null;
        $revendedorAfiliacionId = null;

        if ($datos['propietario_tipo'] === 'cliente_directo') {
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

        $diasVigencia = (int) (ConfiguracionDistribuidora::query()->value('dias_vigencia_vale') ?? 90);
        if ($diasVigencia < 1) {
            $diasVigencia = 90;
        }

        $monto = round((float) $datos['monto_original'], 2);
        $ahora = now();

        return DB::transaction(function () use (
            $distribuidoraId,
            $clienteId,
            $revendedorAfiliacionId,
            $monto,
            $datos,
            $staffId,
            $diasVigencia,
            $ahora
        ) {
            $folio = $this->generarFolio($distribuidoraId);

            $vale = Vale::create([
                'distribuidora_id'            => $distribuidoraId,
                'cliente_directo_id'          => $clienteId,
                'revendedor_distribuidora_id' => $revendedorAfiliacionId,
                'folio'                       => $folio,
                'monto_original'              => $monto,
                'saldo_actual'                => $monto,
                'fecha_emision'               => $ahora,
                'fecha_vencimiento'           => $ahora->copy()->addDays($diasVigencia),
                'estado'                      => 'activo',
                'motivo'                      => $datos['motivo'] ?? null,
                'pedido_origen_id'            => null,
                'creado_por_staff_id'         => $staffId,
            ]);

            ValeMovimiento::create([
                'distribuidora_id'        => $distribuidoraId,
                'vale_id'                 => $vale->id,
                'tipo'                    => 'emision',
                'monto'                   => $monto,
                'saldo_anterior'          => 0,
                'saldo_posterior'         => $monto,
                'pedido_id'               => null,
                'venta_directa_id'        => null,
                'registrado_por_staff_id' => $staffId,
                'observaciones'           => 'Emisión de vale',
            ]);

            return $vale->fresh(['clienteDirecto', 'revendedorAfiliacion', 'movimientos']);
        });
    }

    protected function generarFolio(int $distribuidoraId): string
    {
        $prefijo = 'VAL-' . now()->format('Ymd') . '-';

        $ultimo = Vale::withoutGlobalScopes()
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