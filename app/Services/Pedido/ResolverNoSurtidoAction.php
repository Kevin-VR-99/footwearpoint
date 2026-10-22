<?php

namespace App\Services\Pedido;

use App\Services\Reembolso\ReembolsoMercadoPagoService;
use App\Models\DistribuidoraStaff;
use App\Models\Pago;
use App\Models\PedidoDetalle;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Support\FolioPago;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * TG-241 (A12 / E8-05) — Qué se hace con lo que no se surtió.
 *
 * La persona elige entre dos cosas: que se lo cambien por otro producto, o
 * que le devuelvan su anticipo.
 *
 * Son DOS PASOS a propósito, y es la decisión más importante de esta tarjeta:
 *
 *   1. ELEGIR (registrarDecision): lo puede hacer la persona desde la app o
 *      el personal en el mostrador cuando la persona llama por teléfono.
 *      Aquí no se mueve ni un peso: solo queda anotado qué quiere.
 *
 *   2. ENTREGAR EL DINERO (entregarReembolso): solo el personal, desde el
 *      panel, cuando de verdad le dio el efectivo. Eso crea el pago de
 *      salida.
 *
 * Separarlos importa porque, según el backlog, esta es la ÚNICA situación de
 * todo el sistema en la que sale dinero en efectivo (en lo demás se da un
 * vale). Si la app pudiera crear el pago de salida sola, el sistema estaría
 * diciendo que entregó dinero que nadie entregó.
 */
class ResolverNoSurtidoAction
{
    public const RESOLUCIONES = ['sustitucion', 'reembolso_anticipo'];

    public function __construct(private RegistrarAuditoriaAction $auditoria)
    {
    }

    /** Paso 1: queda anotado qué quiere la persona. No mueve dinero. */
    public function registrarDecision(PedidoDetalle $detalle, string $resolucion): PedidoDetalle
    {
        if (! in_array($resolucion, self::RESOLUCIONES, true)) {
            throw ValidationException::withMessages([
                'resolucion' => ['Solo se puede elegir cambiar el producto o que le devuelvan el anticipo.'],
            ]);
        }

        $this->exigirNoSurtidoPendiente($detalle);

        $detalle->update(['resolucion_no_surtido' => $resolucion]);

        $this->auditoria->ejecutar(
            accion: 'pedido.no_surtido_resuelto',
            entidadTipo: 'pedido_detalle',
            entidadId: $detalle->id,
            datosNuevos: [
                'pedido_id'  => $detalle->pedido_id,
                'resolucion' => $resolucion,
            ],
        );

        return $detalle->fresh();
    }

    /**
     * Paso 2: el personal entregó el efectivo y lo registra.
     *
     * El monto es el anticipo de ESE renglón, y nunca más de lo que de verdad
     * entró como dinero. Lo que se haya cubierto con un vale no se devuelve en
     * efectivo: eso convertiría un vale en dinero, que es justo lo que el
     * negocio evita. Los vales no viven en la tabla de pagos, así que la
     * cuenta de abajo ya los deja fuera sola.
     */
    public function entregarReembolso(PedidoDetalle $detalle, string $metodo = 'efectivo'): Pago
    {
        if ($detalle->estado_surtido !== 'no_surtido') {
            throw ValidationException::withMessages([
                'detalle' => ['Ese renglón no está marcado como no surtido.'],
            ]);
        }

        if ($detalle->resolucion_no_surtido !== 'reembolso_anticipo') {
            throw ValidationException::withMessages([
                'detalle' => ['Primero hay que registrar que la persona eligió que le devuelvan su anticipo.'],
            ]);
        }

        $staffId = $this->staffIdActual();

        if ($staffId === null) {
            throw ValidationException::withMessages([
                'detalle' => ['Solo el personal de la distribuidora puede entregar un reembolso.'],
            ]);
        }

        $monto = $this->montoADevolver($detalle);

        if ($monto <= 0) {
            throw ValidationException::withMessages([
                'detalle' => ['No hay anticipo en efectivo que devolver por ese renglón. Si lo pagó con un vale, el saldo sigue en su vale.'],
            ]);
        }

        $pedido = $detalle->pedido;

        // Si el anticipo se cobró con Mercado Pago, se devuelve ahí mismo
        // (reembolso parcial del cobro, pedido a su API desde la cola).
        $cobroMp = null;
        if ($metodo === 'mercado_pago') {
            app(\App\Services\Auth\PermisoModuloService::class)->exigir(Auth::user(), \App\Services\Auth\PermisoModuloService::PAGOS_REGISTRAR);
            $reembolsosMp = app(ReembolsoMercadoPagoService::class);
            $cobroMp = $pedido->pagos()
                ->where('metodo', 'mercado_pago')->where('direccion', 'entrada')->where('estado', 'aplicado')
                ->orderByDesc('id')->get()
                ->first(fn (Pago $c) => ReembolsoMercadoPagoService::esReembolsable($c) && $reembolsosMp->disponible($c) >= $monto);

            if ($cobroMp === null) {
                throw ValidationException::withMessages([
                    'detalle' => ['Este pedido no tiene un cobro de Mercado Pago del que se pueda devolver ese monto. Devuélvelo en efectivo.'],
                ]);
            }
        }

        return DB::transaction(function () use ($detalle, $pedido, $monto, $metodo, $staffId, $cobroMp) {
            $pago = Pago::create([
                'distribuidora_id'        => $pedido->distribuidora_id,
                'pedido_id'               => $pedido->id,
                'folio'                   => FolioPago::siguiente((int) $pedido->distribuidora_id),
                'tipo'                    => 'reembolso_anticipo',
                'direccion'               => 'salida',
                'metodo'                  => $metodo,
                'monto'                   => $monto,
                'fecha_pago'              => now(),
                'referencia'              => ($cobroMp ? 'Anticipo devuelto en Mercado Pago: ' : 'Anticipo devuelto: ').$detalle->producto_nombre.' no surtido',
                'estado'                  => 'aplicado',
                'registrado_por_staff_id' => $staffId,
            ]);

            $this->auditoria->ejecutar(
                accion: 'pedido.reembolso_anticipo',
                entidadTipo: 'pago',
                entidadId: $pago->id,
                datosNuevos: [
                    'pedido_id'          => $pedido->id,
                    'pedido_detalle_id'  => $detalle->id,
                    'monto'              => $monto,
                    'metodo'             => $metodo,
                ],
            );

            if ($cobroMp !== null) {
                app(ReembolsoMercadoPagoService::class)->solicitar($cobroMp, $monto, 'Anticipo de '.$detalle->producto_nombre.' no surtido', $staffId, (int) $pago->id);
            }

            return $pago;
        });
    }

    /** true si el pedido tiene un cobro de Mercado Pago del que se puede devolver el anticipo de ese renglón. */
    public function puedeDevolverEnMercadoPago(PedidoDetalle $detalle): bool
    {
        $monto = $this->montoADevolver($detalle);
        $reembolsosMp = app(ReembolsoMercadoPagoService::class);

        return $monto > 0 && $detalle->pedido->pagos
            ->where('metodo', 'mercado_pago')->where('direccion', 'entrada')->where('estado', 'aplicado')
            ->contains(fn (Pago $c) => ReembolsoMercadoPagoService::esReembolsable($c) && $reembolsosMp->disponible($c) >= $monto);
    }

    /**
     * Cuánto se le puede devolver en efectivo por este renglón.
     *
     * El tope es el dinero que de verdad entró menos lo que ya se devolvió,
     * para no devolver dos veces ni devolver más de lo que la persona pagó.
     */
    public function montoADevolver(PedidoDetalle $detalle): float
    {
        $pedido = $detalle->pedido;

        $entradas = (float) $pedido->pagos()
            ->where('direccion', 'entrada')
            ->where('estado', 'aplicado')
            ->sum('monto');

        $salidas = (float) $pedido->pagos()
            ->where('direccion', 'salida')
            ->where('estado', 'aplicado')
            ->sum('monto');

        $disponible = max(0, round($entradas - $salidas, 2));

        return round(min((float) $detalle->anticipo_requerido, $disponible), 2);
    }

    private function exigirNoSurtidoPendiente(PedidoDetalle $detalle): void
    {
        if ($detalle->estado_surtido !== 'no_surtido') {
            throw ValidationException::withMessages([
                'detalle' => ['Ese renglón no está marcado como no surtido.'],
            ]);
        }

        if ($detalle->resolucion_no_surtido !== 'pendiente') {
            throw ValidationException::withMessages([
                'detalle' => ['Ese renglón ya se resolvió. Si hay que cambiarlo, avisa a la distribuidora.'],
            ]);
        }
    }

    private function staffIdActual(): ?int
    {
        $usuario = Auth::user();

        if ($usuario === null) {
            return null;
        }

        return DistribuidoraStaff::where('usuario_id', $usuario->id)->value('id');
    }
}
