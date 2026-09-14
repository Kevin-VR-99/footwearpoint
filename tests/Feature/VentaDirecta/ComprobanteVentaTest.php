<?php

namespace Tests\Feature\VentaDirecta;

use App\Mail\ComprobanteVentaMail;
use App\Models\ClienteDirecto;
use App\Models\Distribuidora;
use App\Models\DistribuidoraStaff;
use App\Models\StockLocal;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Models\VentaDirecta;
use App\Services\VentaDirecta\RegistrarVentaDirectaService;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * E7-02 (TG-115) — Comprobante de venta directa.
 *
 * Criterios: incluye producto, variante, precio y fecha; puede imprimirse o
 * enviarse digitalmente (PDF y correo, decisión del equipo: las dos).
 */
class ComprobanteVentaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        $this->actingAs($this->empleado());
    }

    private function empleado(): Usuario
    {
        return Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail();
    }

    /**
     * Una venta real hecha con el mismo servicio del punto de venta.
     */
    private function venderUnaPieza(?int $clienteDirectoId = null): VentaDirecta
    {
        $renglon = Livewire::test('punto-venta.index')->instance()->disponibles->first();
        $this->assertNotNull($renglon, 'El seeder demo no dejó nada con existencia para vender.');

        $resultado = app(RegistrarVentaDirectaService::class)->registrar([[
            'variante_id'         => $renglon['variante_id'],
            'producto_campana_id' => $renglon['producto_campana_id'],
            'cantidad'            => 2,
        ]], 'tarjeta', $clienteDirectoId);

        return $resultado->venta;
    }

    private function urlComprobante(VentaDirecta $venta, string $sufijo = ''): string
    {
        return '/ventas-directas/' . $venta->id . '/comprobante' . $sufijo;
    }

    // ------------------------------------------------------------------
    // Qué lleva
    // ------------------------------------------------------------------

    public function test_el_comprobante_incluye_producto_variante_precio_y_fecha(): void
    {
        $venta = $this->venderUnaPieza();
        $linea = \App\Models\VentaDirectaDetalle::where('venta_directa_id', $venta->id)->firstOrFail();

        $this->get($this->urlComprobante($venta))
            ->assertOk()
            ->assertSee('COMPROBANTE DE VENTA')
            ->assertSee($venta->folio)
            // Producto
            ->assertSee($linea->producto_nombre)
            ->assertSee('Modelo ' . $linea->modelo)
            // Variante
            ->assertSee('Talla ' . $linea->talla)
            ->assertSee($linea->color)
            // Precio
            ->assertSee('$' . number_format((float) $linea->precio_unitario, 2))
            ->assertSee('$' . number_format((float) $venta->total, 2))
            // Fecha
            ->assertSee($venta->fecha_venta->format('d/m/Y H:i'))
            // Pago y leyenda acordada
            ->assertSee('Tarjeta')
            ->assertSee('Este documento no es un comprobante fiscal (CFDI).')
            // Botones de la versión en pantalla
            ->assertSee('Imprimir')
            ->assertSee('Descargar PDF')
            ->assertSee('Enviar por correo');
    }

    public function test_con_cliente_ligado_muestra_su_nombre_y_propone_su_correo(): void
    {
        $cliente = ClienteDirecto::query()->whereNotNull('email')->firstOrFail();
        $venta = $this->venderUnaPieza($cliente->id);

        $this->get($this->urlComprobante($venta))
            ->assertOk()
            ->assertSee($cliente->nombre)
            ->assertSee('value="' . $cliente->email . '"', false);
    }

    public function test_una_venta_anulada_lo_dice_claramente(): void
    {
        $venta = $this->venderUnaPieza();
        $venta->forceFill(['estado' => 'anulada'])->save();

        $this->get($this->urlComprobante($venta))
            ->assertOk()
            ->assertSee('VENTA ANULADA');
    }

    // ------------------------------------------------------------------
    // Imprimir / PDF / correo
    // ------------------------------------------------------------------

    public function test_se_descarga_en_pdf(): void
    {
        $venta = $this->venderUnaPieza();

        $respuesta = $this->get($this->urlComprobante($venta, '/pdf'));

        $respuesta->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString(
            'comprobante-' . $venta->folio . '.pdf',
            $respuesta->headers->get('content-disposition')
        );
        $this->assertStringStartsWith('%PDF', $respuesta->getContent());
    }

    public function test_se_envia_por_correo_con_el_pdf_adjunto(): void
    {
        Mail::fake();
        $venta = $this->venderUnaPieza();

        $this->post($this->urlComprobante($venta, '/enviar'), ['email' => 'cliente@correo.test'])
            ->assertRedirect($this->urlComprobante($venta))
            ->assertSessionHas('status', 'Comprobante enviado a cliente@correo.test.');

        Mail::assertSent(ComprobanteVentaMail::class, function (ComprobanteVentaMail $correo) use ($venta) {
            $adjuntos = $correo->attachments();

            return $correo->hasTo('cliente@correo.test')
                && $correo->comprobante['folio'] === $venta->folio
                && count($adjuntos) === 1
                && str_starts_with($adjuntos[0]->as, 'comprobante-' . $venta->folio);
        });
    }

    public function test_sin_correo_valido_no_se_envia_nada(): void
    {
        Mail::fake();
        $venta = $this->venderUnaPieza();

        $this->from($this->urlComprobante($venta))
            ->post($this->urlComprobante($venta, '/enviar'), ['email' => 'no-es-correo'])
            ->assertSessionHasErrors(['email' => 'El correo no es válido.']);

        Mail::assertNothingSent();
    }

    public function test_el_punto_de_venta_ofrece_ver_el_comprobante_al_cobrar(): void
    {
        $renglon = Livewire::test('punto-venta.index')->instance()->disponibles->first();

        $componente = Livewire::test('punto-venta.index')
            ->call('agregar', $renglon['clave'])
            ->set('metodo_pago', 'efectivo')
            ->call('cobrar')
            ->assertSet('errorMsg', '');

        $venta = VentaDirecta::query()->latest('id')->firstOrFail();

        $componente
            ->assertSet('ultimaVentaId', $venta->id)
            ->assertSee('Ver comprobante')
            ->assertSee(route('ventas-directas.comprobante', $venta->id), false);
    }

    // ------------------------------------------------------------------
    // Quién puede verlo
    // ------------------------------------------------------------------

    public function test_el_comprobante_de_otra_distribuidora_responde_404(): void
    {
        $ventaAjena = $this->ventaDeOtraDistribuidora();

        $this->get('/ventas-directas/' . $ventaAjena . '/comprobante')->assertNotFound();
        $this->get('/ventas-directas/' . $ventaAjena . '/comprobante/pdf')->assertNotFound();
        $this->post('/ventas-directas/' . $ventaAjena . '/comprobante/enviar', ['email' => 'x@correo.test'])
            ->assertNotFound();
    }

    public function test_un_revendedor_no_puede_ver_comprobantes(): void
    {
        $venta = $this->venderUnaPieza();

        $this->app['auth']->forgetGuards();
        Tenant::olvidarCache();
        $this->actingAs(Usuario::where('email', 'maria.lopez@revendedor.test')->firstOrFail());

        $this->get($this->urlComprobante($venta))->assertForbidden();
    }

    public function test_sin_iniciar_sesion_manda_al_login(): void
    {
        $venta = $this->venderUnaPieza();

        $this->app['auth']->forgetGuards();
        auth()->logout();

        $this->get($this->urlComprobante($venta))->assertRedirect(route('login'));
    }

    /**
     * Una venta completa de otra distribuidora, con su sucursal y su
     * empleado. Regresa su id.
     */
    private function ventaDeOtraDistribuidora(): int
    {
        $otra = Distribuidora::create([
            'nombre_comercial' => 'Zapatería Rival (prueba)',
            'slug'             => 'zapateria-rival-' . uniqid(),
            'estado'           => 'activa',
            'fecha_solicitud'  => now(),
            'fecha_aprobacion' => now(),
        ]);

        return Tenant::forzar($otra->id, function () use ($otra) {
            $sucursal = Sucursal::create([
                'distribuidora_id' => $otra->id,
                'nombre'           => 'Matriz Rival',
                'direccion'        => 'Calle Rival 1',
                'es_principal'     => true,
                'activa'           => true,
            ]);

            $usuario = Usuario::create([
                'nombre'   => 'Empleado Rival',
                'email'    => 'empleado.rival.' . uniqid() . '@rival.test',
                'password' => Hash::make('password'),
                'estado'   => 'activo',
            ]);

            $staff = DistribuidoraStaff::create([
                'distribuidora_id' => $otra->id,
                'usuario_id'       => $usuario->id,
                'tipo'             => 'empleado',
                'estado'           => 'activo',
                'fecha_alta'       => now(),
            ]);

            $venta = new VentaDirecta();
            $venta->timestamps = false;
            $venta->forceFill([
                'distribuidora_id'        => $otra->id,
                'sucursal_id'             => $sucursal->id,
                'folio'                   => 'VD-RIVAL-000001',
                'fecha_venta'             => now(),
                'subtotal'                => 100,
                'descuento'               => 0,
                'total'                   => 100,
                'estado'                  => 'completada',
                'registrada_por_staff_id' => $staff->id,
                'created_at'              => now(),
                'updated_at'              => now(),
            ])->save();

            return (int) $venta->id;
        });
    }
}
