<?php

namespace Tests\Feature\Vale;

use App\Models\ClienteDirecto;
use App\Models\Notificacion;
use App\Models\RevendedorDistribuidora;
use App\Models\Usuario;
use App\Models\Vale;
use App\Services\Notificacion\Push\EnviadorPush;
use App\Services\Vale\AvisarValesPorVencerAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * TG-242 (A13 / E16-02) — Avisar que un vale está por vencer.
 *
 * Los vales duran 90 días por omisión y el aviso sale 7 días antes, una sola
 * vez por vale. Lo corre un cron de Railway con
 * php artisan vales:avisar-por-vencer.
 */
class AvisoValePorVencerTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const JOSE = 'jose.hernandez@cliente.test';

    private const MARIA = 'maria.lopez@revendedor.test';

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        config(['vales.dias_aviso_vencimiento' => 7]);

        // El push no se manda de verdad en las pruebas: lo que importa aquí
        // es el aviso de la bandeja, que es el que siempre queda.
        $this->app->instance(EnviadorPush::class, Mockery::mock(EnviadorPush::class)->shouldIgnoreMissing([]));
    }

    private function cuenta(string $email): Usuario
    {
        return Usuario::where('email', $email)->firstOrFail();
    }

    /** Un vale del cliente directo José, que vence en tantos días. */
    private function valeDeJose(int $diasParaVencer, float $saldo = 500.0, string $estado = 'activo'): Vale
    {
        $cliente = ClienteDirecto::withoutGlobalScopes()
            ->where('usuario_id', $this->cuenta(self::JOSE)->id)
            ->firstOrFail();

        return Vale::withoutGlobalScopes()->create([
            'distribuidora_id'    => $cliente->distribuidora_id,
            'cliente_directo_id'  => $cliente->id,
            'folio'               => 'V-'.fake()->unique()->numerify('#####'),
            'monto_original'      => 500.0,
            'saldo_actual'        => $saldo,
            'fecha_emision'       => now()->subDays(80),
            'fecha_vencimiento'   => now()->addDays($diasParaVencer),
            'estado'              => $estado,
            'creado_por_staff_id' => 1,
        ]);
    }

    private function correr(): array
    {
        return app(AvisarValesPorVencerAction::class)->ejecutar();
    }

    private function avisosDe(string $email): int
    {
        return Notificacion::withoutGlobalScopes()
            ->where('usuario_id', $this->cuenta($email)->id)
            ->where('tipo', 'vale_por_vencer')
            ->count();
    }

    // ------------------------------------------------------------------
    // A quién se le avisa
    // ------------------------------------------------------------------

    public function test_avisa_de_un_vale_que_vence_dentro_del_margen(): void
    {
        $vale = $this->valeDeJose(3);

        $resultado = $this->correr();

        $this->assertSame(1, $resultado['avisados']);
        $this->assertSame(1, $this->avisosDe(self::JOSE));

        $aviso = Notificacion::withoutGlobalScopes()->where('tipo', 'vale_por_vencer')->firstOrFail();

        $this->assertStringContainsString($vale->folio, $aviso->titulo);
        $this->assertStringContainsString('vence en 3 días', $aviso->mensaje);
        $this->assertStringContainsString('500.00', $aviso->mensaje);
        $this->assertSame('vale', $aviso->entidad_tipo);
        $this->assertSame($vale->id, (int) $aviso->entidad_id);
    }

    public function test_tambien_avisa_a_un_cliente_mayorista(): void
    {
        $afiliacion = RevendedorDistribuidora::withoutGlobalScopes()
            ->whereHas('revendedor', fn ($q) => $q->where('usuario_id', $this->cuenta(self::MARIA)->id))
            ->firstOrFail();

        Vale::withoutGlobalScopes()->create([
            'distribuidora_id'            => $afiliacion->distribuidora_id,
            'revendedor_distribuidora_id' => $afiliacion->id,
            'folio'                       => 'V-MAYORISTA',
            'monto_original'              => 300.0,
            'saldo_actual'                => 300.0,
            'fecha_emision'               => now()->subDays(80),
            'fecha_vencimiento'           => now()->addDays(2),
            'estado'                      => 'activo',
            'creado_por_staff_id'         => 1,
        ]);

        $this->correr();

        $this->assertSame(1, $this->avisosDe(self::MARIA));
    }

    // ------------------------------------------------------------------
    // A quién NO
    // ------------------------------------------------------------------

    public function test_no_avisa_de_uno_que_todavia_le_falta_mucho(): void
    {
        $this->valeDeJose(30);

        $this->assertSame(0, $this->correr()['avisados']);
        $this->assertSame(0, $this->avisosDe(self::JOSE));
    }

    public function test_no_avisa_de_uno_ya_vencido(): void
    {
        // El aviso llegaria tarde y solo confunde.
        $this->valeDeJose(-2);

        $this->assertSame(0, $this->correr()['avisados']);
    }

    public function test_no_avisa_de_uno_sin_saldo(): void
    {
        // Sin saldo no hay nada que apurarse en usar.
        $this->valeDeJose(3, saldo: 0.0);

        $this->assertSame(0, $this->correr()['avisados']);
    }

    public function test_no_avisa_de_uno_bloqueado_ni_agotado(): void
    {
        $this->valeDeJose(3, estado: 'bloqueado');
        $this->valeDeJose(3, estado: 'agotado');

        $this->assertSame(0, $this->correr()['avisados']);
    }

    public function test_a_quien_no_tiene_cuenta_en_la_app_no_se_le_avisa_ni_se_le_marca(): void
    {
        // Tener cuenta es opcional; a esas personas las llama la
        // distribuidora. Y no se marca, para que si le dan cuenta antes de
        // que venza, el aviso le llegue igual.
        $sinCuenta = ClienteDirecto::withoutGlobalScopes()->whereNull('usuario_id')->firstOrFail();

        $vale = Vale::withoutGlobalScopes()->create([
            'distribuidora_id'    => $sinCuenta->distribuidora_id,
            'cliente_directo_id'  => $sinCuenta->id,
            'folio'               => 'V-SIN-CUENTA',
            'monto_original'      => 200.0,
            'saldo_actual'        => 200.0,
            'fecha_emision'       => now()->subDays(80),
            'fecha_vencimiento'   => now()->addDays(3),
            'estado'              => 'activo',
            'creado_por_staff_id' => 1,
        ]);

        $resultado = $this->correr();

        $this->assertSame(0, $resultado['avisados']);
        $this->assertSame(1, $resultado['sin_cuenta']);
        $this->assertNull($vale->fresh()->aviso_vencimiento_enviado_at);
    }

    // ------------------------------------------------------------------
    // Una sola vez
    // ------------------------------------------------------------------

    public function test_solo_avisa_una_vez_aunque_el_comando_corra_todos_los_dias(): void
    {
        $vale = $this->valeDeJose(5);

        $this->correr();
        $this->assertNotNull($vale->fresh()->aviso_vencimiento_enviado_at);

        // Al día siguiente, y al otro.
        $this->correr();
        $this->correr();

        $this->assertSame(1, $this->avisosDe(self::JOSE), 'Le llegó el mismo aviso más de una vez.');
    }

    // ------------------------------------------------------------------
    // Cómo se lee el aviso
    // ------------------------------------------------------------------

    public function test_el_aviso_dice_manana_y_hoy_en_vez_de_un_numero(): void
    {
        // "Vence en 0 días" se lee como un error del sistema.
        $this->valeDeJose(1);
        $this->correr();

        $this->assertStringContainsString(
            'vence mañana',
            Notificacion::withoutGlobalScopes()->where('tipo', 'vale_por_vencer')->latest('id')->value('mensaje')
        );

        Notificacion::withoutGlobalScopes()->where('tipo', 'vale_por_vencer')->delete();

        $this->valeDeJose(0);
        $this->correr();

        $this->assertStringContainsString(
            'vence hoy',
            Notificacion::withoutGlobalScopes()->where('tipo', 'vale_por_vencer')->latest('id')->value('mensaje')
        );
    }

    // ------------------------------------------------------------------
    // El comando
    // ------------------------------------------------------------------

    public function test_el_comando_corre_y_reporta_lo_que_hizo(): void
    {
        $this->valeDeJose(3);

        $this->artisan('vales:avisar-por-vencer')
            ->expectsOutputToContain('Avisados: 1')
            ->assertSuccessful();
    }

    public function test_el_comando_acepta_otro_margen_de_dias(): void
    {
        // Sirve para probar sin esperar a que un vale se acerque de verdad.
        $this->valeDeJose(20);

        $this->artisan('vales:avisar-por-vencer', ['--dias' => 30])
            ->expectsOutputToContain('Avisados: 1')
            ->assertSuccessful();

        $this->assertSame(1, $this->avisosDe(self::JOSE));
    }

    public function test_el_aviso_se_guarda_con_la_distribuidora_del_vale(): void
    {
        // El comando mira todas las distribuidoras, pero cada aviso queda
        // con la suya: nadie ve nada de otra.
        $vale = $this->valeDeJose(3);

        $this->correr();

        $this->assertSame(
            (int) $vale->distribuidora_id,
            (int) Notificacion::withoutGlobalScopes()->where('tipo', 'vale_por_vencer')->value('distribuidora_id')
        );
    }
}
