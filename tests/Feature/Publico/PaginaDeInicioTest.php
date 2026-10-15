<?php

namespace Tests\Feature\Publico;

use App\Models\PlanSuscripcion;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TG-219 (E15-04) — La página pública de FootwearPoint en "/".
 *
 * Antes "/" mandaba al login. Ahora cuenta qué es FootwearPoint, cómo
 * funciona, sus beneficios, los planes con sus precios de verdad y el
 * contacto, y enlaza al marketplace y a los documentos legales.
 */
class PaginaDeInicioTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    // ------------------------------------------------------------------
    // Ya no saca a nadie al login
    // ------------------------------------------------------------------

    public function test_el_login_tiene_un_boton_para_volver_al_inicio(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Volver al inicio')
            ->assertSee('href="'.route('inicio').'"', false);
    }

    public function test_la_raiz_muestra_la_pagina_y_ya_no_redirige_al_login(): void
    {
        $this->assertGuest();

        $respuesta = $this->get('/');

        // assertOk ya dice que no hubo redirección; esto fija además que la
        // ruta con nombre "inicio" es la raíz del sitio.
        $respuesta->assertOk();
        $respuesta->assertDontSee('Iniciar sesión en FootwearPoint');
        $this->assertSame(url('/'), route('inicio'));
    }

    public function test_cuenta_que_es_como_funciona_y_sus_beneficios(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Una distribuidora de calzado, ordenada de principio a fin')
            ->assertSee('Cómo funciona')
            ->assertSee('Eliges tus líneas')
            // Lo que se activa son líneas (las empresas del catálogo); las
            // marcas van dentro de cada línea.
            ->assertSee('activas las líneas que vendes')
            ->assertSee('Qué ganas')
            ->assertSee('Tu tienda en línea');
    }

    // ------------------------------------------------------------------
    // Los planes salen de la base, no escritos a mano
    // ------------------------------------------------------------------

    public function test_muestra_los_planes_con_los_precios_de_la_base(): void
    {
        $planes = PlanSuscripcion::where('activo', true)->get();

        $this->assertNotEmpty($planes, 'El seeder tendría que dejar planes activos');

        $respuesta = $this->get('/')->assertOk();

        foreach ($planes as $plan) {
            $respuesta->assertSee($plan->nombre);
            $respuesta->assertSee('$'.number_format((float) $plan->precio_base_mensual, 2));
        }
    }

    public function test_si_cambia_un_precio_en_la_base_cambia_la_pagina(): void
    {
        $plan = PlanSuscripcion::where('activo', true)->orderBy('precio_base_mensual')->firstOrFail();

        $this->get('/')->assertOk()->assertDontSee('$1,234.00');

        $plan->update(['precio_base_mensual' => 1234.00]);

        $this->get('/')->assertOk()->assertSee('$1,234.00');
    }

    public function test_un_plan_inactivo_no_aparece(): void
    {
        $plan = PlanSuscripcion::where('activo', true)->firstOrFail();

        $this->get('/')->assertOk()->assertSee($plan->nombre);

        $plan->update(['activo' => false]);

        $this->get('/')->assertOk()->assertDontSee($plan->nombre);
    }

    public function test_sin_planes_no_deja_la_seccion_vacia(): void
    {
        PlanSuscripcion::query()->update(['activo' => false]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Estamos actualizando nuestros planes.');
    }

    // ------------------------------------------------------------------
    // Botones y enlaces
    // ------------------------------------------------------------------

    public function test_sin_sesion_ofrece_solicitar_el_alta_y_ver_el_marketplace(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Solicitar mi alta')
            ->assertSee('Ver marketplace')
            ->assertSee(route('marketplace'), false);
    }

    /** TG-220 ya registró la ruta, así que el botón la sigue. */
    public function test_el_boton_del_alta_lleva_al_formulario(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('alta.solicitar'));

        $this->get('/')
            ->assertOk()
            ->assertSee(route('alta.solicitar'), false)
            ->assertDontSee('href="#contacto"', false);
    }

    public function test_enlaza_al_aviso_de_privacidad_y_a_los_terminos(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(route('aviso-privacidad'), false)
            ->assertSee(route('terminos'), false);
    }

    // ------------------------------------------------------------------
    // Con sesión no se saca a nadie de la página
    // ------------------------------------------------------------------

    public function test_con_sesion_de_personal_sale_el_boton_a_su_panel(): void
    {
        $this->actingAs(Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail());

        $this->get('/')
            ->assertOk()
            ->assertSee('Ir al panel')
            ->assertSee(route('dashboard'), false)
            ->assertDontSee('Solicitar mi alta');
    }

    public function test_con_sesion_del_admin_general_el_boton_va_a_su_panel(): void
    {
        $this->actingAs(Usuario::where('email', \Database\Seeders\AdminGeneralSeeder::EMAIL)->firstOrFail());

        $this->get('/')
            ->assertOk()
            ->assertSee('Ir al panel admin')
            ->assertSee(route('admin.dashboard'), false);
    }

    // ------------------------------------------------------------------
    // Contacto: si no está configurado, no se inventa nada
    // ------------------------------------------------------------------

    public function test_muestra_el_contacto_que_este_configurado(): void
    {
        config([
            'footwearpoint.contacto.email' => 'hola@footwearpoint.app',
            'footwearpoint.contacto.telefono' => '9631112233',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('hola@footwearpoint.app')
            ->assertSee('9631112233');
    }

    public function test_un_dato_de_contacto_vacio_no_se_muestra(): void
    {
        config([
            'footwearpoint.contacto.email' => 'hola@footwearpoint.app',
            'footwearpoint.contacto.telefono' => null,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('hola@footwearpoint.app')
            ->assertDontSee('tel:', false);
    }

    public function test_sin_contacto_configurado_la_pagina_abre_igual(): void
    {
        config([
            'footwearpoint.contacto.email' => null,
            'footwearpoint.contacto.telefono' => null,
            // TG-234: sin esto el icono de correo toma MAIL_FROM_ADDRESS.
            'footwearpoint.redes.correo' => null,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('¿Hablamos?')
            ->assertDontSee('mailto:', false);
    }
}
