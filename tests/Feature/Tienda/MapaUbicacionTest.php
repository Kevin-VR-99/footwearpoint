<?php

namespace Tests\Feature\Tienda;

use App\Models\Distribuidora;
use App\Models\Sucursal;
use App\Services\Tienda\EnlaceTienda;
use App\Support\MapaUbicacion;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TG-269 (U9, E15-02) - Mapa con la ubicación en la tienda pública, enlace
 * "Ver en mapa" en el marketplace y url_mapa en GET /api/marketplace.
 */
class MapaUbicacionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function ramirez(): Distribuidora
    {
        return Distribuidora::where('slug', 'calzados-ramirez')->firstOrFail();
    }

    private function tienda(Distribuidora $distribuidora)
    {
        return $this->get(app(EnlaceTienda::class)->url($distribuidora));
    }

    private function sucursalPrincipal(Distribuidora $distribuidora): Sucursal
    {
        return Sucursal::withoutGlobalScopes()
            ->where('distribuidora_id', $distribuidora->id)
            ->where('es_principal', true)
            ->firstOrFail();
    }

    public function test_la_tienda_con_direccion_muestra_el_mapa_y_como_llegar(): void
    {
        $ramirez = $this->ramirez();
        $ramirez->update(['direccion_publica' => 'Av. Central 123, Comitán, Chiapas']);
        $consulta = rawurlencode('Av. Central 123, Comitán, Chiapas, México');

        $this->tienda($ramirez)
            ->assertOk()
            ->assertSee('Ubicación')
            ->assertSee('https://maps.google.com/maps?q='.$consulta.'&output=embed')
            ->assertSee('Cómo llegar')
            ->assertSee('https://www.google.com/maps/dir/?api=1&destination='.$consulta);
    }

    public function test_la_tienda_sin_direccion_no_muestra_mapa(): void
    {
        $ramirez = $this->ramirez();
        $ramirez->update(['direccion_publica' => null]);
        $this->sucursalPrincipal($ramirez)->update(['direccion' => '']);

        $this->tienda($ramirez)
            ->assertOk()
            ->assertDontSee('maps.google.com')
            ->assertDontSee('Cómo llegar');
    }

    public function test_sin_direccion_publica_usa_la_de_la_sucursal_principal(): void
    {
        $ramirez = $this->ramirez();
        $ramirez->update(['direccion_publica' => '  ']);
        $this->sucursalPrincipal($ramirez)->update(['direccion' => 'Calle Sucursal 9, Comitán']);

        $this->tienda($ramirez)
            ->assertOk()
            ->assertSee('Calle Sucursal 9, Comitán')
            ->assertSee('q='.rawurlencode('Calle Sucursal 9, Comitán, México'));

        $this->assertSame('Calle Sucursal 9, Comitán', MapaUbicacion::direccion($ramirez->fresh()));
    }

    public function test_la_direccion_se_codifica_y_se_escapa(): void
    {
        $ramirez = $this->ramirez();
        $ramirez->update(['direccion_publica' => 'Calle 5 #12 & Juárez <b>, Tuxtla']);

        $respuesta = $this->tienda($ramirez)->assertOk();

        $respuesta->assertSee('q='.rawurlencode('Calle 5 #12 & Juárez <b>, Tuxtla, México'));
        $respuesta->assertDontSee('<b>, Tuxtla', false);
        $respuesta->assertSee('Calle 5 #12 &amp; Juárez &lt;b&gt;, Tuxtla', false);
    }

    public function test_consulta_agrega_mexico_solo_si_falta(): void
    {
        $this->assertNull(MapaUbicacion::consulta(null));
        $this->assertNull(MapaUbicacion::consulta('   '));
        $this->assertSame('Av. Central 123, México', MapaUbicacion::consulta(' Av. Central 123 '));
        $this->assertSame('Centro, Comitán, Chiapas, México', MapaUbicacion::consulta('Centro, Comitán, Chiapas, México'));
        $this->assertSame('Centro, Mexico', MapaUbicacion::consulta('Centro, Mexico'));

        $sinGuardar = new Distribuidora(['direccion_publica' => null]);
        $this->assertNull(MapaUbicacion::urlInsertar($sinGuardar));
        $this->assertNull(MapaUbicacion::urlComoLlegar($sinGuardar));
        $this->assertNull(MapaUbicacion::urlVerEnMapa($sinGuardar));

        $conDireccion = new Distribuidora(['direccion_publica' => 'Av. Central 123, Comitán']);
        $this->assertSame(
            'https://www.google.com/maps/search/?api=1&query='.rawurlencode('Av. Central 123, Comitán, México'),
            MapaUbicacion::urlVerEnMapa($conDireccion),
        );
    }

    public function test_el_marketplace_enlaza_ver_en_mapa_solo_con_direccion(): void
    {
        $ramirez = $this->ramirez();
        $ramirez->update(['direccion_publica' => 'Av. Central 123, Comitán, Chiapas']);

        Livewire::test('marketplace.index')
            ->assertSee('Ver en mapa')
            ->assertSee(MapaUbicacion::urlVerEnMapa($ramirez));

        Distribuidora::query()->update(['direccion_publica' => null]);
        Sucursal::withoutGlobalScopes()->update(['direccion' => '']);

        Livewire::test('marketplace.index')->assertDontSee('Ver en mapa');
    }

    public function test_la_api_del_marketplace_trae_url_mapa(): void
    {
        $ramirez = $this->ramirez();
        $ramirez->update(['direccion_publica' => 'Av. Central 123, Comitán, Chiapas']);

        $fila = collect($this->getJson('/api/marketplace')->assertOk()->json('data'))
            ->firstWhere('slug', 'calzados-ramirez');

        $this->assertSame(MapaUbicacion::urlVerEnMapa($ramirez), $fila['url_mapa']);

        $ramirez->update(['direccion_publica' => null]);
        $this->sucursalPrincipal($ramirez)->update(['direccion' => '']);

        $fila = collect($this->getJson('/api/marketplace')->assertOk()->json('data'))
            ->firstWhere('slug', 'calzados-ramirez');

        $this->assertArrayHasKey('url_mapa', $fila);
        $this->assertNull($fila['url_mapa']);
    }
}
