<?php

namespace App\Services\Caja;

use App\Exceptions\OperacionInvalidaException;
use App\Models\CajaMovimiento;
use App\Models\CajaSesion;
use App\Models\DistribuidoraStaff;
use App\Models\Pago;
use App\Models\Sucursal;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Services\Auth\PermisoModuloService;
use App\Support\ContextoOperativo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Caja del punto de venta: apertura con fondo, ingresos/retiros, corte.
 *
 * Cada persona del personal tiene a lo más UNA caja abierta. Mientras está
 * abierta, lo que cobra o devuelve en mostrador queda en ella
 * (Pago::booted). Lo esperado por método es:
 *
 *   efectivo = fondo inicial + cobros − devoluciones + ingresos − retiros
 *   otros    = cobros − devoluciones
 *
 * y al cerrar se compara con lo que se contó.
 */
class CajaService
{
    public const METODOS = ['efectivo', 'transferencia', 'tarjeta', 'otro'];

    public function __construct(
        private ContextoOperativo $contexto,
        private RegistrarAuditoriaAction $auditoria,
        private PermisoModuloService $permisos,
    ) {
    }

    public function abiertaDe(?DistribuidoraStaff $staff = null): ?CajaSesion
    {
        $staff ??= $this->contexto->staff();

        return CajaSesion::query()
            ->where('abierta_por_staff_id', $staff->id)
            ->where('estado', 'abierta')
            ->latest('id')
            ->first();
    }

    public function abrir(float $fondo, ?int $sucursalId = null, ?string $notas = null): CajaSesion
    {
        $this->exigirPermiso();

        if ($fondo < 0 || $fondo > 9_999_999) {
            throw ValidationException::withMessages(['fondo' => ['El fondo inicial no puede ser negativo.']]);
        }

        $staff = $this->contexto->staff();
        $sucursal = $sucursalId !== null
            ? Sucursal::query()->whereKey($sucursalId)->where('activa', true)->first()
            : $this->contexto->sucursalPrincipal();

        if ($sucursal === null) {
            throw ValidationException::withMessages(['sucursal' => ['La sucursal no existe o no está activa.']]);
        }

        return DB::transaction(function () use ($staff, $sucursal, $fondo, $notas) {
            // Bloquear al staff evita abrir dos cajas con dos clics seguidos.
            DistribuidoraStaff::query()->whereKey($staff->id)->lockForUpdate()->first();

            if ($this->abiertaDe($staff) !== null) {
                throw new OperacionInvalidaException('Ya tienes una caja abierta. Haz su corte antes de abrir otra.', 409);
            }

            $caja = CajaSesion::create([
                'distribuidora_id'     => $staff->distribuidora_id,
                'sucursal_id'          => $sucursal->id,
                'abierta_por_staff_id' => $staff->id,
                'estado'               => 'abierta',
                'fondo_inicial'        => round($fondo, 2),
                'abierta_at'           => now(),
                'notas'                => $notas !== null && trim($notas) !== '' ? mb_substr(trim($notas), 0, 500) : null,
            ]);

            $this->auditoria->ejecutar('caja.abierta', 'caja_sesion', (int) $caja->id, null, [
                'fondo_inicial' => (float) $caja->fondo_inicial,
                'sucursal_id'   => (int) $sucursal->id,
            ]);

            return $caja;
        });
    }

    public function registrarMovimiento(string $tipo, float $monto, string $motivo): CajaMovimiento
    {
        $this->exigirPermiso();

        if (! in_array($tipo, ['ingreso', 'retiro'], true)) {
            throw ValidationException::withMessages(['tipo' => ['Elige ingreso o retiro.']]);
        }
        if ($monto <= 0) {
            throw ValidationException::withMessages(['monto' => ['El monto debe ser mayor que cero.']]);
        }
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 3) {
            throw ValidationException::withMessages(['motivo' => ['Escribe el motivo.']]);
        }

        return DB::transaction(function () use ($tipo, $monto, $motivo) {
            $caja = $this->abiertaBloqueada();

            if ($tipo === 'retiro') {
                $efectivo = $this->resumen($caja)['metodos']['efectivo']['esperado'];
                if (round($monto, 2) > round($efectivo, 2)) {
                    throw new OperacionInvalidaException(
                        'No puedes retirar más efectivo del que hay en caja ($'.number_format($efectivo, 2).').',
                        422
                    );
                }
            }

            $movimiento = CajaMovimiento::create([
                'distribuidora_id'        => $caja->distribuidora_id,
                'caja_sesion_id'          => $caja->id,
                'tipo'                    => $tipo,
                'monto'                   => round($monto, 2),
                'motivo'                  => mb_substr($motivo, 0, 300),
                'registrado_por_staff_id' => $this->contexto->staff()->id,
            ]);

            $this->auditoria->ejecutar('caja.'.$tipo, 'caja_sesion', (int) $caja->id, null, [
                'monto' => (float) $movimiento->monto, 'motivo' => $movimiento->motivo,
            ]);

            return $movimiento;
        });
    }

    /**
     * @param  array<string, float|int|string|null>  $contado  método => importe contado
     */
    public function cerrar(array $contado, ?string $notas = null): CajaSesion
    {
        $this->exigirPermiso();

        $limpio = [];
        foreach (self::METODOS as $metodo) {
            $valor = $contado[$metodo] ?? 0;
            if ($valor === '' || $valor === null) {
                $valor = 0;
            }
            if (! is_numeric($valor) || (float) $valor < 0) {
                throw ValidationException::withMessages(['contado.'.$metodo => ['El importe contado no puede ser negativo.']]);
            }
            $limpio[$metodo] = round((float) $valor, 2);
        }

        return DB::transaction(function () use ($limpio, $notas) {
            $caja = $this->abiertaBloqueada();
            $resumen = $this->resumen($caja);

            $esperado = [];
            foreach ($resumen['metodos'] as $metodo => $datos) {
                $esperado[$metodo] = $datos['esperado'];
            }

            $diferencia = round(array_sum($limpio) - array_sum($esperado), 2);

            $caja->forceFill([
                'estado'               => 'cerrada',
                'cerrada_at'           => now(),
                'cerrada_por_staff_id' => $this->contexto->staff()->id,
                'esperado'             => $esperado,
                'contado'              => $limpio,
                'diferencia'           => $diferencia,
                'notas'                => trim(collect([$caja->notas, $notas])->filter(fn ($n) => trim((string) $n) !== '')->implode(' · ')) ?: null,
            ])->save();

            $this->auditoria->ejecutar('caja.cerrada', 'caja_sesion', (int) $caja->id, null, [
                'esperado'   => $esperado,
                'contado'    => $limpio,
                'diferencia' => $diferencia,
            ]);

            return $caja;
        });
    }

    /**
     * Totales de una caja (abierta o cerrada) por método de pago.
     *
     * @return array{metodos: array<string, array{cobros: float, devoluciones: float, ingresos: float, retiros: float, esperado: float}>, esperado_total: float, ventas: int, canceladas: int, pagos: \Illuminate\Support\Collection}
     */
    public function resumen(CajaSesion $caja): array
    {
        $pagos = Pago::query()->where('caja_sesion_id', $caja->id)->where('estado', 'aplicado')->orderBy('id')->get();
        $movimientos = CajaMovimiento::query()->where('caja_sesion_id', $caja->id)->get();

        $metodos = [];
        foreach (self::METODOS as $metodo) {
            $delMetodo = $pagos->where('metodo', $metodo);
            $cobros = round((float) $delMetodo->where('direccion', 'entrada')->sum('monto'), 2);
            $devoluciones = round((float) $delMetodo->where('direccion', 'salida')->sum('monto'), 2);
            $ingresos = $metodo === 'efectivo' ? round((float) $movimientos->where('tipo', 'ingreso')->sum('monto'), 2) : 0.0;
            $retiros = $metodo === 'efectivo' ? round((float) $movimientos->where('tipo', 'retiro')->sum('monto'), 2) : 0.0;
            $fondo = $metodo === 'efectivo' ? (float) $caja->fondo_inicial : 0.0;

            $metodos[$metodo] = [
                'cobros'       => $cobros,
                'devoluciones' => $devoluciones,
                'ingresos'     => $ingresos,
                'retiros'      => $retiros,
                'esperado'     => round($fondo + $cobros - $devoluciones + $ingresos - $retiros, 2),
            ];
        }

        $ventasIds = $pagos->where('tipo', 'venta_directa')->pluck('venta_directa_id')->filter()->unique();

        return [
            'metodos'        => $metodos,
            'esperado_total' => round(array_sum(array_column($metodos, 'esperado')), 2),
            'ventas'         => $ventasIds->count(),
            'canceladas'     => $pagos->where('tipo', 'reembolso_venta')->pluck('venta_directa_id')->unique()->count(),
            'pagos'          => $pagos,
            'movimientos'    => $movimientos,
        ];
    }

    private function abiertaBloqueada(): CajaSesion
    {
        $caja = $this->abiertaDe();

        if ($caja === null) {
            throw new OperacionInvalidaException('No tienes una caja abierta.', 409);
        }

        return CajaSesion::query()->whereKey($caja->id)->lockForUpdate()->firstOrFail();
    }

    private function exigirPermiso(): void
    {
        $this->permisos->exigir(Auth::user(), PermisoModuloService::CAJA_OPERAR);
    }
}
