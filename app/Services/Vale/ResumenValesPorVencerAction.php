<?php

namespace App\Services\Vale;

use App\Models\DistribuidoraStaff;
use App\Models\Notificacion;
use App\Models\Vale;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * TG-122 — Resumen diario de vales por vencer para el personal.
 *
 * Lo corre el mismo comando vales:avisar-por-vencer, después del aviso a
 * cada cliente (TG-242). A cada distribuidora con vales por vencer le deja
 * UN aviso en la campana del panel de su personal activo:
 *
 *     «3 vales vencen en los próximos 7 días»
 *     «2 clientes sin app (llámalos): V-00012 Ana Ruiz, V-00015 Luis Paz.»
 *
 * Los que no tienen cuenta en la app no reciben el aviso de TG-242, por eso
 * se listan: la distribuidora es quien les llama.
 *
 *  - Solo si hay al menos un vale por vencer.
 *  - Una vez al día por distribuidora: si hoy ya existe un resumen suyo, no
 *    se repite (sin columna nueva; se mira la propia tabla de avisos).
 *  - Cuenta todos los vales por vencer, aunque al cliente ya se le haya
 *    avisado otro día: es el panorama de hoy.
 */
class ResumenValesPorVencerAction
{
    public const TIPO = 'vales_por_vencer_resumen';

    /** Cuántos clientes sin app se nombran en el aviso; del resto, solo cuántos. */
    public const MAX_LISTADOS = 10;

    /**
     * @return array{distribuidoras: int, avisos: int}
     */
    public function ejecutar(?int $dias = null): array
    {
        $dias = $dias ?? Vale::diasAvisoVencimiento();

        $porDistribuidora = Vale::withoutGlobalScopes()
            ->with(['clienteDirecto', 'revendedorAfiliacion.revendedor'])
            ->porVencer($dias)
            ->orderBy('fecha_vencimiento')
            ->get()
            ->groupBy('distribuidora_id');

        $resumidas = 0;
        $avisos = 0;

        foreach ($porDistribuidora as $distribuidoraId => $vales) {
            try {
                if ($this->yaSeAvisoHoy((int) $distribuidoraId)) {
                    continue;
                }

                $enviados = $this->avisar((int) $distribuidoraId, $vales, $dias);

                if ($enviados > 0) {
                    $resumidas++;
                    $avisos += $enviados;
                }
            } catch (Throwable $e) {
                Log::error('No se pudo mandar el resumen de vales por vencer.', [
                    'distribuidora_id' => $distribuidoraId,
                    'error'            => $e->getMessage(),
                ]);
            }
        }

        return ['distribuidoras' => $resumidas, 'avisos' => $avisos];
    }

    /** El texto del aviso: [título, mensaje]. */
    public static function textos(Collection $vales, int $dias): array
    {
        $n = $vales->count();
        $plazo = match (true) {
            $dias <= 0 => 'hoy',
            $dias === 1 => 'hoy o mañana',
            default => "en los próximos {$dias} días",
        };
        $titulo = $n === 1 ? "1 vale vence {$plazo}" : "{$n} vales vencen {$plazo}";

        $sinApp = $vales->filter(fn (Vale $v) => self::cuentaDe($v) === null)->values();

        if ($sinApp->isEmpty()) {
            return [$titulo, 'Todos tienen la app y ya recibieron su aviso.'];
        }

        $nombres = $sinApp->take(self::MAX_LISTADOS)
            ->map(fn (Vale $v) => trim($v->folio.' '.(self::nombreDe($v) ?? '')))
            ->implode(', ');
        $resto = $sinApp->count() - self::MAX_LISTADOS;
        $m = $sinApp->count();

        $mensaje = ($m === 1 ? '1 cliente sin app' : "{$m} clientes sin app")
            .' (llámalos): '.$nombres
            .($resto > 0 ? " y {$resto} más" : '')
            .'.';

        return [$titulo, $mensaje];
    }

    private function avisar(int $distribuidoraId, Collection $vales, int $dias): int
    {
        [$titulo, $mensaje] = self::textos($vales, $dias);

        $staff = DistribuidoraStaff::withoutGlobalScopes()
            ->where('distribuidora_id', $distribuidoraId)
            ->where('estado', 'activo')
            ->whereNotNull('usuario_id')
            ->pluck('usuario_id')
            ->unique();

        foreach ($staff as $usuarioId) {
            Notificacion::withoutGlobalScopes()->create([
                'usuario_id'       => (int) $usuarioId,
                'distribuidora_id' => $distribuidoraId,
                'tipo'             => self::TIPO,
                'titulo'           => $titulo,
                'mensaje'          => $mensaje,
                'leida_at'         => null,
                'entidad_tipo'     => null,
                'entidad_id'       => null,
            ]);
        }

        return $staff->count();
    }

    private function yaSeAvisoHoy(int $distribuidoraId): bool
    {
        return Notificacion::withoutGlobalScopes()
            ->where('distribuidora_id', $distribuidoraId)
            ->where('tipo', self::TIPO)
            ->where('created_at', '>=', now()->startOfDay())
            ->exists();
    }

    /** Igual que en AvisarValesPorVencerAction (TG-242). */
    private static function cuentaDe(Vale $vale): ?int
    {
        $usuarioId = $vale->clienteDirecto?->usuario_id
            ?? $vale->revendedorAfiliacion?->revendedor?->usuario_id;

        return $usuarioId !== null ? (int) $usuarioId : null;
    }

    private static function nombreDe(Vale $vale): ?string
    {
        return $vale->clienteDirecto?->nombre ?? $vale->revendedorAfiliacion?->revendedor?->nombre;
    }
}
