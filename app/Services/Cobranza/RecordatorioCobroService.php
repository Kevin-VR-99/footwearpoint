<?php

namespace App\Services\Cobranza;

use App\Exceptions\OperacionInvalidaException;
use App\Mail\RecordatorioCobroMail;
use App\Models\Auditoria;
use App\Models\ClienteDirecto;
use App\Models\Distribuidora;
use App\Models\Pedido;
use App\Models\RevendedorDistribuidora;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Services\Pedido\RegistrarPagoPedidoAction;
use App\Services\Reporte\PagosDePedidos;
use App\Services\Reporte\PedidosPorRevendedorAction;
use App\Support\Tenant;
use Illuminate\Support\Facades\Mail;

/**
 * Recordatorio de cobro por correo a un deudor (cliente directo o cliente
 * mayorista) desde la pestaña «Por cobrar» de Reportes.
 *
 * - Solo deudores de la distribuidora actual (los modelos van con su scope de tenant).
 * - Máximo uno por deudor cada 24 horas; el envío queda en la auditoría y
 *   esa misma auditoría es la que marca el límite.
 * - El correo va en cola (RecordatorioCobroMail implementa ShouldQueue).
 */
class RecordatorioCobroService
{
    public const ACCION = 'cobro.recordatorio';
    public const HORAS_ENTRE_RECORDATORIOS = 24;
    public const TIPOS = ['cliente_directo', 'revendedor'];

    public function __construct(
        private PagosDePedidos $pagos,
        private RegistrarAuditoriaAction $auditoria,
    ) {
    }

    /**
     * Todo lo que el correo (y la vista previa) necesitan del deudor.
     *
     * @return array{tipo:string,id:int,nombre:string,email:?string,saldo:float,pedidos:array,distribuidora:array,ultimo:?string,disponible:bool,motivo:?string}
     */
    public function datos(string $tipo, int $id): array
    {
        [$nombre, $email, $columna] = $this->deudor($tipo, $id);
        $pedidos = $this->pedidosConSaldo($columna, $id);
        $ultimo = $this->ultimoEnvio($tipo, $id);
        $casa = Distribuidora::query()->findOrFail(Tenant::id());

        $motivo = match (true) {
            blank($email) => 'No tiene correo registrado.',
            $pedidos === [] => 'Ya no tiene saldo pendiente.',
            $ultimo !== null => 'Ya se le envió un recordatorio el '.$ultimo->timezone(config('app.timezone'))->format('d/m/Y H:i').'. Espera 24 horas.',
            default => null,
        };

        return [
            'tipo' => $tipo,
            'id' => $id,
            'nombre' => $nombre,
            'email' => $email,
            'saldo' => round(array_sum(array_column($pedidos, 'pendiente')), 2),
            'pedidos' => $pedidos,
            'distribuidora' => [
                'nombre' => (string) $casa->nombre_comercial,
                'telefono' => $casa->telefono_publico,
                'email' => $casa->email_publico,
                'direccion' => $casa->direccion_publica,
            ],
            'ultimo' => $ultimo?->toIso8601String(),
            'disponible' => $motivo === null,
            'motivo' => $motivo,
        ];
    }

    /** Envía (en cola) y registra. Lanza OperacionInvalidaException si no se puede. */
    public function enviar(string $tipo, int $id): array
    {
        $datos = $this->datos($tipo, $id);

        if (! $datos['disponible']) {
            throw new OperacionInvalidaException($datos['nombre'].': '.$datos['motivo'], 422);
        }

        Mail::to($datos['email'], $datos['nombre'])->queue(new RecordatorioCobroMail($datos));

        $this->auditoria->ejecutar(self::ACCION, $tipo, $id, null, [
            'email' => $datos['email'],
            'saldo' => $datos['saldo'],
            'pedidos' => array_column($datos['pedidos'], 'folio'),
        ]);

        return $datos;
    }

    /** @return array{0:string,1:?string,2:string} nombre, correo y columna del pedido */
    private function deudor(string $tipo, int $id): array
    {
        if ($tipo === 'cliente_directo') {
            $cliente = ClienteDirecto::query()->with('usuario')->findOrFail($id);

            return [(string) $cliente->nombre, $cliente->email ?: $cliente->usuario?->email, 'cliente_directo_id'];
        }

        if ($tipo === 'revendedor') {
            $afiliacion = RevendedorDistribuidora::query()->with('revendedor.usuario')->findOrFail($id);
            $revendedor = $afiliacion->revendedor;

            return [(string) ($revendedor?->nombre ?? 'Cliente mayorista #'.$id), $revendedor?->email ?: $revendedor?->usuario?->email, 'revendedor_distribuidora_id'];
        }

        throw new OperacionInvalidaException('Tipo de deudor no válido.', 422);
    }

    /** Mismo criterio que MontosPendientesAction: pedidos vivos con saldo. */
    private function pedidosConSaldo(string $columna, int $id): array
    {
        $pedidos = Pedido::query()
            ->where($columna, $id)
            ->whereNotIn('estado', array_merge(PedidosPorRevendedorAction::ESTADOS_EXCLUIDOS_EXTRA, RegistrarPagoPedidoAction::ESTADOS_CERRADOS))
            ->orderBy('fecha_colocacion')
            ->get(['id', 'folio', 'fecha_colocacion', 'created_at', 'total']);

        $pagado = $this->pagos->pagadoPorPedido($pedidos->pluck('id')->all());

        return $pedidos
            ->map(fn (Pedido $p) => [
                'folio' => (string) ($p->folio ?? '#'.$p->id),
                'fecha' => ($p->fecha_colocacion ?? $p->created_at)?->format('d/m/Y'),
                'total' => round((float) $p->total, 2),
                'pagado' => round((float) ($pagado[$p->id] ?? 0), 2),
                'pendiente' => round(max(0, (float) $p->total - (float) ($pagado[$p->id] ?? 0)), 2),
            ])
            ->filter(fn ($p) => $p['pendiente'] > 0)
            ->values()
            ->all();
    }

    private function ultimoEnvio(string $tipo, int $id): ?\Illuminate\Support\Carbon
    {
        return Auditoria::query()
            ->where('distribuidora_id', Tenant::id())
            ->where('accion', self::ACCION)
            ->where('entidad_tipo', $tipo)
            ->where('entidad_id', $id)
            ->where('created_at', '>=', now()->subHours(self::HORAS_ENTRE_RECORDATORIOS))
            ->latest('created_at')
            ->first()?->created_at;
    }
}
