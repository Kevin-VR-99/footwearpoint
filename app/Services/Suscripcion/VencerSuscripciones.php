<?php

namespace App\Services\Suscripcion;

use App\Mail\AvisoVencimientoSuscripcionMail;
use App\Models\Distribuidora;
use App\Models\DistribuidoraStaff;
use App\Models\Suscripcion;
use App\Models\SuscripcionAviso;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Support\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Pasa a vencida la suscripción cuyo último día pagado ya pasó, manda el
 * aviso de ese día y, solo en modo suspender, suspende por falta de pago
 * al sexto día. Corrido dos veces el mismo día no repite nada.
 */
class VencerSuscripciones
{
    public function __construct(private readonly RegistrarAuditoriaAction $auditoria)
    {
    }

    /**
     * @return array{revisadas: int, vencidas: int, suspendidas: int, avisos: int}
     */
    public function ejecutar(bool $suspender, bool $simular = false): array
    {
        $resultado = ['revisadas' => 0, 'vencidas' => 0, 'suspendidas' => 0, 'avisos' => 0];

        $ids = Suscripcion::withoutGlobalScopes()
            ->whereIn('estado', ['activa', 'vencida'])
            ->whereNotNull('fecha_fin')
            ->selectRaw('MAX(id) as id')
            ->groupBy('distribuidora_id')
            ->pluck('id');

        foreach ($ids as $id) {
            $suscripcion = Suscripcion::withoutGlobalScopes()->find($id);

            if ($suscripcion === null) {
                continue;
            }

            try {
                $parcial = Tenant::forzar(
                    (int) $suscripcion->distribuidora_id,
                    fn () => $this->una($suscripcion, $suspender, $simular)
                );
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            foreach ($resultado as $clave => $valor) {
                $resultado[$clave] += $parcial[$clave];
            }
        }

        return $resultado;
    }

    /**
     * @return array{revisadas: int, vencidas: int, suspendidas: int, avisos: int}
     */
    private function una(Suscripcion $suscripcion, bool $suspender, bool $simular): array
    {
        $parcial = ['revisadas' => 0, 'vencidas' => 0, 'suspendidas' => 0, 'avisos' => 0];
        $correos = [];

        DB::transaction(function () use ($suscripcion, $suspender, $simular, &$parcial, &$correos) {
            $actual = Suscripcion::query()->whereKey($suscripcion->id)->lockForUpdate()->first();

            if ($actual === null || $actual->fecha_fin === null || ! in_array($actual->estado, ['activa', 'vencida'], true)) {
                return;
            }

            $distribuidora = Distribuidora::query()->lockForUpdate()->find($actual->distribuidora_id);

            if ($distribuidora === null) {
                return;
            }

            $parcial['revisadas'] = 1;
            $dias = CalendarioVencimiento::diasDesde($actual->fecha_fin);
            $hoy = today()->toDateString();

            if ($simular) {
                return;
            }

            // Una suspensión manual no se convierte en falta de pago.
            if ($distribuidora->estado === 'suspendida' && ! $distribuidora->suspendidaPorFaltaDePago()) {
                if ($actual->estado === 'activa' && $dias >= 1) {
                    $this->marcarVencida($actual);
                    $parcial['vencidas'] = 1;
                }

                return;
            }

            if ($actual->estado === 'activa' && $dias >= 1) {
                $this->marcarVencida($actual);
                $parcial['vencidas'] = 1;
            }

            $tipo = $this->tipoDeAviso($dias, $suspender, $distribuidora);

            if ($tipo === SuscripcionAviso::SUSPENDIDA && $distribuidora->estado === 'activa') {
                $distribuidora->update([
                    'estado' => 'suspendida',
                    'motivo_suspension' => Distribuidora::MOTIVO_FALTA_PAGO,
                ]);
                $this->auditoria->ejecutar(
                    'distribuidora.suspendida_falta_pago',
                    'distribuidora',
                    $distribuidora->id,
                    ['estado' => 'activa', 'motivo_suspension' => null],
                    [
                        'estado' => 'suspendida',
                        'motivo_suspension' => Distribuidora::MOTIVO_FALTA_PAGO,
                        'suscripcion_id' => $actual->id,
                        'fecha_fin' => $actual->fecha_fin->toDateString(),
                    ]
                );
                $parcial['suspendidas'] = 1;
            }

            if ($tipo !== null && $this->reservarAviso($actual, $tipo, $hoy)) {
                $correos[] = [
                    'distribuidora' => $distribuidora->fresh(),
                    'suscripcion' => $actual->fresh(),
                    'tipo' => $tipo,
                    'dias' => $dias,
                ];
                $parcial['avisos'] = 1;
            }
        });

        foreach ($correos as $correo) {
            $this->enviar($correo['distribuidora'], $correo['suscripcion'], $correo['tipo'], $correo['dias']);
        }

        return $parcial;
    }

    private function marcarVencida(Suscripcion $suscripcion): void
    {
        $antes = $suscripcion->estado;
        $suscripcion->update(['estado' => 'vencida']);
        $this->auditoria->ejecutar(
            'suscripcion.vencida',
            'suscripcion',
            $suscripcion->id,
            ['estado' => $antes],
            ['estado' => 'vencida', 'fecha_fin' => $suscripcion->fecha_fin?->toDateString()]
        );
    }

    private function tipoDeAviso(int $dias, bool $suspender, Distribuidora $distribuidora): ?string
    {
        return match (true) {
            $dias === -3 => SuscripcionAviso::POR_VENCER_3,
            $dias === -1 => SuscripcionAviso::POR_VENCER_1,
            $dias === 0 => SuscripcionAviso::VENCE_HOY,
            CalendarioVencimiento::enGracia($dias) => SuscripcionAviso::GRACIA,
            $dias >= CalendarioVencimiento::DIAS_GRACIA + 1 && $suspender && $distribuidora->estado === 'activa' => SuscripcionAviso::SUSPENDIDA,
            $dias >= CalendarioVencimiento::DIAS_GRACIA + 1 && ! $suspender => SuscripcionAviso::SIGUE_VENCIDA,
            default => null,
        };
    }

    private function reservarAviso(Suscripcion $suscripcion, string $tipo, string $hoy): bool
    {
        try {
            $aviso = SuscripcionAviso::query()->firstOrCreate([
                'suscripcion_id' => $suscripcion->id,
                'tipo' => $tipo,
                'fecha' => $hoy,
            ], [
                'distribuidora_id' => $suscripcion->distribuidora_id,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return $aviso->wasRecentlyCreated;
    }

    private function enviar(Distribuidora $distribuidora, Suscripcion $suscripcion, string $tipo, int $dias): void
    {
        $texto = $this->texto($distribuidora, $suscripcion, $tipo, $dias);

        foreach ($this->destinatarios($distribuidora) as $correo => $nombre) {
            try {
                Mail::to($correo)->send(new AvisoVencimientoSuscripcionMail(
                    nombreDistribuidora: $distribuidora->nombre_comercial,
                    tipo: $tipo,
                    texto: $texto,
                    nombreDestinatario: $nombre,
                ));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    private function texto(Distribuidora $distribuidora, Suscripcion $suscripcion, string $tipo, int $dias): string
    {
        $fecha = $suscripcion->fecha_fin?->format('d/m/Y');
        $nombre = $distribuidora->nombre_comercial;
        $quedan = CalendarioVencimiento::diasAntesDeSuspender($dias);

        return match ($tipo) {
            SuscripcionAviso::POR_VENCER_3 => "La mensualidad de {$nombre} vence el {$fecha}. Entra al panel para pagarla.",
            SuscripcionAviso::POR_VENCER_1 => "La mensualidad de {$nombre} vence mañana, el {$fecha}. Entra al panel para pagarla.",
            SuscripcionAviso::VENCE_HOY => "Hoy, {$fecha}, es el último día pagado de la mensualidad de {$nombre}. Si no se paga, empiezan 5 días de gracia.",
            SuscripcionAviso::GRACIA => "La mensualidad de {$nombre} venció el {$fecha}. "
                .($quedan === 1 ? 'Queda 1 día' : "Quedan {$quedan} días")
                .' antes de que se suspenda el acceso. Si pagas ahora, la fecha de vencimiento se conserva.',
            SuscripcionAviso::SUSPENDIDA => "{$nombre} quedó suspendida por falta de pago. El personal puede entrar al panel solo para pagar. Al confirmarse el pago se reactiva, y el mes cuenta desde ese día.",
            default => "La mensualidad de {$nombre} sigue sin pagarse desde el {$fecha}. Páguela desde el panel para regularizarla.",
        };
    }

    /** @return array<string, ?string> */
    private function destinatarios(Distribuidora $distribuidora): array
    {
        $administradores = DistribuidoraStaff::with('usuario')
            ->where('distribuidora_id', $distribuidora->id)
            ->where('tipo', 'administrador')
            ->where('estado', 'activo')
            ->orderBy('id')
            ->get();

        $destinatarios = [];

        foreach ($administradores as $staff) {
            $correo = strtolower(trim((string) $staff->usuario?->email));

            if ($correo !== '') {
                $destinatarios[$correo] = $staff->usuario->nombreVisible();
            }
        }

        $publico = strtolower(trim((string) $distribuidora->email_publico));

        if ($publico !== '' && ! array_key_exists($publico, $destinatarios)) {
            $destinatarios[$publico] = null;
        }

        return $destinatarios;
    }
}
