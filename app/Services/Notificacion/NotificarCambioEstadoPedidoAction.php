<?php

namespace App\Services\Notificacion;

use App\Models\DispositivoFcm;
use App\Models\DistribuidoraStaff;
use App\Models\Notificacion;
use App\Models\Pedido;
use App\Services\Notificacion\Push\EnviadorPush;
use App\Support\Estados;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotificarCambioEstadoPedidoAction
{
    public function __construct(private EnviadorPush $enviadorPush)
    {
    }

    public function ejecutar(Pedido $pedido, string $estadoAnterior, string $estadoNuevo): void
    {
        if ($estadoAnterior === $estadoNuevo) {
            return;
        }

        // TG-281: con los nombres como se leen, no como los guarda la base.
        // Antes decia "Pedido PED-015 -> listo_entrega" y "cambio de
        // «colocado» a «listo_entrega»". El personal lee esto todos los dias.
        $titulo = 'Pedido '.$pedido->folio.': '.Estados::etiqueta($estadoNuevo);
        $mensaje = sprintf(
            'El pedido %s cambió de «%s» a «%s».',
            $pedido->folio,
            Estados::etiqueta($estadoAnterior),
            Estados::etiqueta($estadoNuevo)
        );

        // TG-125: el personal tiene que decidir qué hacer con él.
        if ($estadoNuevo === 'vencido_recoleccion') {
            $titulo = 'Pedido '.$pedido->folio.': venció el plazo de recolección';
            $mensaje = 'El cliente no recogió el pedido '.$pedido->folio
                .($pedido->fecha_limite_recoleccion ? ' antes del '.self::fecha($pedido->fecha_limite_recoleccion) : '')
                .'. Decide si se sustituye o se reembolsa el anticipo.';
        }

        // TG-224: sin scope a propósito; filtra por la distribuidora del pedido y puede correr sin sesión de usuario (p. ej. aviso de Mercado Pago).
        $staffs = DistribuidoraStaff::withoutGlobalScopes()
            ->where('distribuidora_id', $pedido->distribuidora_id)
            ->where('estado', 'activo')
            ->whereNotNull('usuario_id')
            ->get(['usuario_id']);

        foreach ($staffs as $staff) {
            $this->crear(
                (int) $staff->usuario_id,
                (int) $pedido->distribuidora_id,
                'pedido_estado',
                $titulo,
                $mensaje,
                $pedido->id
            );
        }

        if (in_array($estadoNuevo, ['recibido_distribuidora', 'listo_entrega'], true)) {
            $this->notificarLlegadaAlDueno($pedido, $estadoNuevo);
        } elseif (isset(self::AVISOS_AL_DUENO[$estadoNuevo])) {
            $this->notificarEstadoAlDueno($pedido, $estadoNuevo);
        }

        // TG-281: al cliente tambien hay que decirle que se le paso el plazo.
        //
        // Hasta ahora esto solo se le avisaba al personal: el cliente perdia
        // el plazo y su anticipo quedaba en el aire sin que nadie se lo
        // dijera. Es dinero suyo.
        if ($estadoNuevo === 'vencido_recoleccion') {
            $this->notificarRecoleccionVencidaAlDueno($pedido);
        }

        // TG-281: y que le rechazaron el pedido, que hasta ahora tampoco se
        // le decia.
        if ($estadoNuevo === 'rechazado') {
            $this->notificarRechazoAlDueno($pedido);
        }
    }

    /** TG-281: se le paso el plazo para recoger. */
    protected function notificarRecoleccionVencidaAlDueno(Pedido $pedido): void
    {
        $usuarioId = $this->duenoDe($pedido);

        if ($usuarioId === null) {
            return;
        }

        $titulo = 'Se venció el plazo para recoger tu pedido '.$pedido->folio;
        $mensaje = 'No se recogió el pedido '.$pedido->folio
            .($pedido->fecha_limite_recoleccion
                ? ' antes del '.self::fecha($pedido->fecha_limite_recoleccion)
                : '')
            .'. Comunícate con tu distribuidora para ver qué sigue con tu anticipo.';

        $this->crear(
            $usuarioId,
            (int) $pedido->distribuidora_id,
            'recoleccion_vencida',
            $titulo,
            $mensaje,
            $pedido->id
        );

        $this->mandarPush($usuarioId, $titulo, $mensaje, $pedido);
    }

    /** TG-281: le rechazaron el pedido. */
    protected function notificarRechazoAlDueno(Pedido $pedido): void
    {
        $usuarioId = $this->duenoDe($pedido);

        if ($usuarioId === null) {
            return;
        }

        $titulo = 'Tu pedido '.$pedido->folio.' fue rechazado';
        $mensaje = 'La distribuidora rechazó el pedido '.$pedido->folio
            .'. Comunícate con ella para saber por qué y qué sigue.';

        $this->crear(
            $usuarioId,
            (int) $pedido->distribuidora_id,
            'pedido_rechazado',
            $titulo,
            $mensaje,
            $pedido->id
        );

        $this->mandarPush($usuarioId, $titulo, $mensaje, $pedido);
    }

    /** La cuenta del dueño del pedido, o null si todavía no tiene. */
    protected function duenoDe(Pedido $pedido): ?int
    {
        $pedido->loadMissing(['clienteDirecto', 'revendedorAfiliacion.revendedor']);

        $usuarioId = $pedido->tipo === 'cliente_directo'
            ? $pedido->clienteDirecto?->usuario_id
            : $pedido->revendedorAfiliacion?->revendedor?->usuario_id;

        return $usuarioId !== null ? (int) $usuarioId : null;
    }

    /**
     * Otros cambios de estado que le importan al dueño del pedido y que antes
     * solo veía el personal. rechazado y vencido_recoleccion tienen su aviso
     * propio (TG-281) y no_surtido el de NotificarNoSurtidoAction.
     */
    private const AVISOS_AL_DUENO = [
        'confirmado'          => ['Tu pedido %s fue confirmado', 'La distribuidora confirmó tu pedido %s.'],
        'entregado'           => ['Tu pedido %s fue entregado', 'Se registró la entrega de tu pedido %s. ¡Gracias por tu compra!'],
    ];

    protected function notificarEstadoAlDueno(Pedido $pedido, string $estadoNuevo): void
    {
        $usuarioId = $this->duenoDe($pedido);
        if (! $usuarioId) {
            return;
        }

        [$titulo, $mensaje] = self::AVISOS_AL_DUENO[$estadoNuevo];
        $titulo = sprintf($titulo, $pedido->folio);
        $mensaje = sprintf($mensaje, $pedido->folio);

        $this->crear($usuarioId, (int) $pedido->distribuidora_id, 'pedido_estado_cliente', $titulo, $mensaje, $pedido->id);
        $this->mandarPush($usuarioId, $titulo, $mensaje, $pedido, 'pedido_estado_cliente');
    }

    protected function notificarLlegadaAlDueno(Pedido $pedido, string $estadoNuevo): void
    {
        $usuarioId = $this->duenoDe($pedido);

        if (! $usuarioId) {
            return;
        }

        $titulo = 'Tu pedido '.$pedido->folio.' ya está en sucursal';
        $mensaje = $estadoNuevo === 'listo_entrega'
            // TG-125: con la fecha límite, para que no se le pase.
            ? ($pedido->fecha_limite_recoleccion
                ? 'Ya puedes pasar a recoger y liquidar el saldo pendiente. Recógelo antes del '.self::fecha($pedido->fecha_limite_recoleccion).'.'
                : 'Ya puedes pasar a recoger y liquidar el saldo pendiente.')
            : 'La mercancía de tu pedido llegó a la distribuidora. Te avisaremos cuando esté listo para entrega.';

        $this->crear(
            (int) $usuarioId,
            (int) $pedido->distribuidora_id,
            'pedido_llegada',
            $titulo,
            $mensaje,
            $pedido->id
        );

        // E16-01 (TG-135): además de la bandeja, aviso push a sus celulares.
        $this->mandarPush((int) $usuarioId, $titulo, $mensaje, $pedido);
    }

    /**
     * Push a los celulares del dueño del pedido (TG-135).
     *
     * Solo al dueño, no al personal: ellos trabajan en el panel web y ya
     * tienen su notificación en la bandeja.
     *
     * Dos reglas:
     *
     * 1. Sale DESPUÉS de que se confirme la transacción. El cambio a
     *    "recibido_distribuidora" ocurre dentro de una transacción (al marcar
     *    un ciclo como recibido): si el push saliera adentro y luego algo
     *    fallara, la transacción se desharía pero el aviso ya habría llegado
     *    al celular. Sin transacción abierta, afterCommit corre de inmediato.
     *
     * 2. Si Firebase falla (sin internet, sin llave, llave inválida) NO se cae
     *    nada: el pedido ya cambió de estado y la notificación de la bandeja
     *    ya existe. Solo se deja constancia en el log.
     */
    protected function mandarPush(int $usuarioId, string $titulo, string $mensaje, Pedido $pedido, string $tipo = 'pedido_llegada'): void
    {
        $tokens = DispositivoFcm::where('usuario_id', $usuarioId)->pluck('token')->all();

        if ($tokens === []) {
            return;
        }

        DB::afterCommit(function () use ($tokens, $usuarioId, $titulo, $mensaje, $pedido, $tipo) {
            try {
                $invalidos = $this->enviadorPush->enviar($tokens, $titulo, $mensaje, [
                    // Para que la app abra el pedido al tocar el aviso. Firebase
                    // solo acepta texto en estos datos.
                    'tipo'         => $tipo,
                    'entidad_tipo' => 'pedido',
                    'entidad_id'   => (string) $pedido->id,
                ]);

                // Celulares donde ya no existe la app: se borran para no
                // seguir intentando.
                if ($invalidos !== []) {
                    DispositivoFcm::whereIn('token', $invalidos)->delete();
                }
            } catch (Throwable $e) {
                Log::warning('No se pudo mandar el push de cambio de estado del pedido.', [
                    'pedido_id'  => $pedido->id,
                    'usuario_id' => $usuarioId,
                    'error'      => $e->getMessage(),
                ]);
            }
        });
    }

    /** Fecha corta en hora de México: 15/10/2026. */
    public static function fecha(\DateTimeInterface $fecha): string
    {
        return \Illuminate\Support\Carbon::instance($fecha)->timezone('America/Mexico_City')->format('d/m/Y');
    }

    protected function crear(
        int $usuarioId,
        int $distribuidoraId,
        string $tipo,
        string $titulo,
        string $mensaje,
        int $pedidoId
    ): void {
        Notificacion::create([
            'usuario_id'       => $usuarioId,
            'distribuidora_id' => $distribuidoraId,
            'tipo'             => $tipo,
            'titulo'           => $titulo,
            'mensaje'          => $mensaje,
            'leida_at'         => null,
            'entidad_tipo'     => 'pedido',
            'entidad_id'       => $pedidoId,
        ]);
    }
}