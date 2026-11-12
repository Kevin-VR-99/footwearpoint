<?php

namespace App\Services\Vale;

use App\Models\ConfiguracionDistribuidora;
use App\Models\Vale;
use App\Models\ValeMovimiento;
use App\Services\Notificacion\NotificarValeEmitidoAction;
use App\Support\Centavos;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TG-356 — Crea un vale con tipo (devolución de producto o sobrante) y su
 * movimiento de emisión. Reutiliza el folio y el staff de EmitirValeAction sin
 * cambiar ese flujo (que sigue creando vales legacy, sin tipo).
 *
 * Debe llamarse dentro de una transacción abierta por quien la usa.
 * Vigencia: exactamente 90 días desde la emisión (misma convención de hora
 * exacta que los vales existentes; el corte de fin de día sigue pendiente).
 */
class CrearValeTipificado extends EmitirValeAction
{
    /**
     * Serializa los vales tipificados de una distribuidora. Debe ser lo PRIMERO
     * de la transacción: en MariaDB la foto de lectura nace con la primera
     * lectura, así que tomándola después del bloqueo se ven los folios ya
     * confirmados (prueba de concurrencia real: sin esto 7 de 8 chocaban, y una
     * lectura FOR UPDATE tardía falla con 1020 por innodb_snapshot_isolation).
     */
    public function bloquearNumeracion(int $distribuidoraId): void
    {
        DB::table('distribuidoras')->where('id', $distribuidoraId)->lockForUpdate()->value('id');
    }

    protected function generarFolio(int $distribuidoraId, int $salto = 0): string
    {
        $prefijo = 'VAL-'.now()->format('Ymd').'-';
        // Sin el scope global, igual que EmitirValeAction: el filtro de distribuidora_id
        // va explícito. No lee folios de otra casa. La carrera de folio del emisor
        // legacy (sin este bloqueo) queda fuera de este camino.
        $ultimo = Vale::withoutGlobalScopes()->where('distribuidora_id', $distribuidoraId)
            ->where('folio', 'like', $prefijo.'%')->orderByDesc('folio')->value('folio');
        $secuencia = ($ultimo && preg_match('/-(\d+)$/', $ultimo, $m) ? (int) $m[1] + 1 : 1) + $salto;

        return $prefijo.str_pad((string) $secuencia, 4, '0', STR_PAD_LEFT);
    }

    /**
     * El emisor legacy (EmitirValeAction) no toma este bloqueo; si ganó el mismo
     * folio en paralelo, se prueba el siguiente (la foto no lo ve, por eso el salto).
     */
    private function conFolioUnico(int $distribuidoraId, callable $crear): Vale
    {
        for ($intento = 0; ; $intento++) {
            try {
                return $crear($this->generarFolio($distribuidoraId, $intento));
            } catch (UniqueConstraintViolationException $e) {
                if ($intento >= 10 || ! str_contains($e->getMessage(), 'uq_vale_tenant_folio')) {
                    throw $e;
                }
            }
        }
    }

    /**
     * La nota de devolución vence según «Días de vigencia del vale» de su
     * distribuidora (Configuración general), el mismo ajuste de los vales
     * normales. Los demás tipos, sobrante incluido, duran siempre
     * Vale::DIAS_VIGENCIA_TIPIFICADO. Sin configuración (o con un valor
     * inválido de antes del mínimo) se usa ese mismo número.
     */
    public function diasDeVigencia(string $tipo, int $distribuidoraId): int
    {
        if ($tipo !== Vale::TIPO_DEVOLUCION) {
            return Vale::DIAS_VIGENCIA_TIPIFICADO;
        }

        $dias = (int) ConfiguracionDistribuidora::withoutGlobalScopes()
            ->where('distribuidora_id', $distribuidoraId)
            ->value('dias_vigencia_vale');

        return $dias >= 1 ? $dias : Vale::DIAS_VIGENCIA_TIPIFICADO;
    }

    public function staffActual(): ?int
    {
        return $this->staffIdActual();
    }

    /**
     * @param  array{distribuidora_id:int, cliente_directo_id:?int, revendedor_distribuidora_id:?int}  $propietario
     */
    public function crear(string $tipo, array $propietario, int $centavos, ?int $staffId, string $motivo,
        ?int $pedidoOrigenId = null, ?int $valeOrigenId = null, ?string $clave = null, ?int $pedidoMovimientoId = null): Vale
    {
        $usuarioId = auth()->id();
        if ($staffId === null && ($tipo !== Vale::TIPO_SOBRANTE || $valeOrigenId === null || $usuarioId === null)) {
            // Solo un sobrante puede nacer sin staff, y siempre con su usuario autor (CHECK chk_vale_autor).
            throw new \LogicException('Un vale sin staff solo puede ser un sobrante con usuario autor.');
        }
        if ($centavos <= 0) {
            throw new \LogicException('Un vale debe ser mayor a cero.');
        }

        $this->bloquearNumeracion($propietario['distribuidora_id']);

        $ahora = now();
        $monto = Centavos::aDecimal($centavos);

        $vale = $this->conFolioUnico($propietario['distribuidora_id'], fn (string $folio) => Vale::create([
            'distribuidora_id' => $propietario['distribuidora_id'],
            'cliente_directo_id' => $propietario['cliente_directo_id'],
            'revendedor_distribuidora_id' => $propietario['revendedor_distribuidora_id'],
            'folio' => $folio,
            'monto_original' => $monto,
            'saldo_actual' => $monto,
            'fecha_emision' => $ahora,
            'fecha_vencimiento' => $ahora->copy()->addDays($this->diasDeVigencia($tipo, $propietario['distribuidora_id'])),
            'estado' => 'activo',
            'tipo' => $tipo,
            'motivo' => mb_substr($motivo, 0, 300),
            'pedido_origen_id' => $pedidoOrigenId,
            'vale_origen_id' => $valeOrigenId,
            'clave_idempotencia' => $clave,
            'creado_por_staff_id' => $staffId,
            'creado_por_usuario_id' => $usuarioId,
        ]));

        ValeMovimiento::create([
            'distribuidora_id' => $vale->distribuidora_id,
            'vale_id' => $vale->id,
            'tipo' => 'emision',
            'monto' => $monto,
            'saldo_anterior' => '0.00',
            'saldo_posterior' => $monto,
            'pedido_id' => $pedidoMovimientoId,
            'venta_directa_id' => null,
            'registrado_por_staff_id' => $staffId,
            'observaciones' => mb_substr($motivo, 0, 300),
        ]);

        DB::afterCommit(function () use ($vale) {
            try {
                app(NotificarValeEmitidoAction::class)->ejecutar($vale->fresh(['clienteDirecto', 'revendedorAfiliacion', 'movimientos']));
            } catch (\Throwable $e) {
                Log::warning('No se pudo avisar del vale emitido.', ['vale_id' => $vale->id, 'error' => $e->getMessage()]);
            }
        });

        return $vale;
    }
}
