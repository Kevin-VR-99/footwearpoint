<?php

namespace Tests\Unit;

use App\Models\Notificacion;
use App\Support\GruposNotificacion;
use App\Support\Services\AgruparNotificaciones;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** TG-196: la campana agrupa las notificaciones como Mercado Libre. */
class GruposNotificacionTest extends TestCase
{
    public function test_cada_tipo_existente_cae_en_su_grupo(): void
    {
        $this->assertSame('pedidos', GruposNotificacion::de('pedido_estado'));
        $this->assertSame('pedidos', GruposNotificacion::de('pedido_llegada'));
        $this->assertSame('pedidos', GruposNotificacion::de('pedido_anticipo_pendiente'));
        $this->assertSame('pagos', GruposNotificacion::de('pago_mercado_pago'));
        $this->assertSame('pagos', GruposNotificacion::de('pago_suscripcion'));
        $this->assertSame('pagos', GruposNotificacion::de('vale_por_vencer'));
    }

    public function test_tipos_nuevos_caen_por_prefijo_o_en_generales(): void
    {
        $this->assertSame('pedidos', GruposNotificacion::de('pedido_cancelado'));
        $this->assertSame('promociones', GruposNotificacion::de('promocion_nueva'));
        $this->assertSame('generales', GruposNotificacion::de('algo_raro'));
        $this->assertSame('generales', GruposNotificacion::de(null));
    }

    public function test_resumen_trae_los_cuatro_grupos_con_contador_y_vista_previa(): void
    {
        $n = fn (string $tipo, string $titulo, bool $leida, int $min) => new Notificacion([
            'tipo' => $tipo, 'titulo' => $titulo, 'mensaje' => str_repeat('texto largo ', 20),
            'leida_at' => $leida ? Carbon::now() : null,
        ]);

        $grupos = (new AgruparNotificaciones)->agrupar([
            $n('pedido_estado', 'Pedido nuevo', false, 1),
            $n('pedido_llegada', 'Pedido viejo', false, 2),
            $n('vale_por_vencer', 'Vale', true, 3),
        ])->keyBy('clave');

        $this->assertSame(['pedidos', 'pagos', 'promociones', 'generales'], $grupos->keys()->all());
        $this->assertSame(2, $grupos['pedidos']['no_leidas']);
        $this->assertStringStartsWith('Pedido nuevo', $grupos['pedidos']['vista_previa']);
        $this->assertLessThanOrEqual(AgruparNotificaciones::LARGO_VISTA_PREVIA + 3, mb_strlen($grupos['pedidos']['vista_previa']));
        $this->assertSame(0, $grupos['pagos']['no_leidas']);
        $this->assertSame(1, $grupos['pagos']['total']);
        $this->assertNull($grupos['promociones']['vista_previa']);
    }
}
