<?php

namespace Tests\Feature\Suscripcion;

use App\Models\Distribuidora;
use App\Models\Pago;
use App\Models\PlanSuscripcion;
use App\Models\Suscripcion;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\AdminGeneralSeeder;
use Database\Seeders\DemoDistribuidoraSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class IngresosMercadoPagoAdminTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function como(string $email): Usuario
    {
        $this->app['auth']->forgetGuards();
        $usuario = Usuario::where('email', $email)->firstOrFail();
        $this->actingAs($usuario);
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        app(PermissionRegistrar::class)->setPermissionsTeamId(Tenant::id() ?? 0);

        return $usuario;
    }

    public function test_el_admin_ve_la_cuenta_y_los_ingresos_junto_a_cerrar_sesion(): void
    {
        $token = 'token-de-prueba-que-no-debe-verse';
        config(['services.mercadopago.access_token' => $token]);
        Http::fake([
            'https://api.mercadopago.com/users/me' => Http::response([
                'id' => 1319757182,
                'nickname' => 'CUENTAFP',
                'email' => 'cobros@footwearpoint.test',
                'first_name' => 'Angel',
                'last_name' => 'Alcazar',
            ]),
        ]);

        $distribuidora = Distribuidora::where('slug', DemoDistribuidoraSeeder::SLUG)->firstOrFail();
        $plan = PlanSuscripcion::where('nombre', 'Pro')->firstOrFail();
        $suscripcion = Suscripcion::withoutGlobalScopes()->create([
            'distribuidora_id' => $distribuidora->id,
            'plan_id' => $plan->id,
            'fecha_inicio' => now()->toDateString(),
            'fecha_fin' => now()->addMonth()->toDateString(),
            'estado' => 'activa',
            'precio_base_contratado' => 5,
            'lineas_incluidas_contratadas' => 1,
            'precio_linea_extra_contratado' => 0,
            'lineas_extra_contratadas' => 0,
            'renovacion_automatica' => false,
        ]);
        Pago::withoutGlobalScopes()->create([
            'distribuidora_id' => $distribuidora->id,
            'suscripcion_id' => $suscripcion->id,
            'folio' => 'PAG-PRUEBA-INGRESO',
            'tipo' => 'suscripcion',
            'direccion' => 'entrada',
            'metodo' => 'mercado_pago',
            'monto' => 5,
            'fecha_pago' => now(),
            'estado' => 'aplicado',
        ]);
        Pago::withoutGlobalScopes()->create([
            'distribuidora_id' => $distribuidora->id,
            'folio' => 'PAG-PRUEBA-VENTA',
            'tipo' => 'venta_directa',
            'direccion' => 'entrada',
            'metodo' => 'efectivo',
            'monto' => 80,
            'fecha_pago' => now(),
            'estado' => 'aplicado',
        ]);

        $this->como(AdminGeneralSeeder::EMAIL);

        $menu = $this->get(route('admin.dashboard'))->assertOk()->getContent();
        $menu = substr($menu, strpos($menu, 'data-menu-usuario'));
        $this->assertStringContainsString('data-menu-ingresos', $menu);
        $this->assertLessThan(
            strpos($menu, 'Cerrar sesión'),
            strpos($menu, 'data-menu-ingresos')
        );

        $this->get(route('admin.ingresos'))
            ->assertOk()
            ->assertSee('Cuenta activa')
            ->assertSee('Angel Alcazar')
            ->assertSee('cobros@footwearpoint.test')
            ->assertSee('CUENTAFP')
            ->assertSee('1319757182')
            ->assertSee($distribuidora->nombre_comercial)
            ->assertSee('Pro')
            ->assertSee('$5.00')
            ->assertDontSee('$80.00')
            ->assertDontSee($token);
    }

    public function test_sin_token_avisa_y_una_distribuidora_no_entra(): void
    {
        config(['services.mercadopago.access_token' => '']);

        $this->como(AdminGeneralSeeder::EMAIL);
        $this->get(route('admin.ingresos'))
            ->assertOk()
            ->assertSee('No hay una cuenta de Mercado Pago configurada');

        $this->como('admin@calzadosramirez.test');
        $this->get(route('admin.ingresos'))->assertForbidden();
        $panel = $this->get(route('stock.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('admin.ingresos'), $panel);
    }
}
