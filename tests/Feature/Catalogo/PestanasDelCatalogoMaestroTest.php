<?php

namespace Tests\Feature\Catalogo;

use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\AdminGeneralSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TG-282 — Cada pestaña del catálogo maestro enseña lo suyo, y solo lo suyo.
 *
 * Reportado: al entrar a Colores salían los productos. La causa era que el
 * bloque de productos estaba como @else, o sea de cajón de sastre: cualquier
 * pestaña que no fuera Líneas, Marcas o Temporadas caía ahí. Colores se
 * dibujaba debajo, así que parecía que la pestaña no servía.
 *
 * Esto no se había notado porque ninguna prueba miraba qué enseña cada
 * pestaña; las que había comprobaban las acciones, no la navegación.
 */
class PestanasDelCatalogoMaestroTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);
    }

    private function panel()
    {
        return Livewire::actingAs(Usuario::where('email', AdminGeneralSeeder::EMAIL)->firstOrFail())
            ->test('admin.catalogo-maestro');
    }

    /** Lo que identifica a cada pestaña cuando está abierta. */
    private const SUYO = [
        'lineas'     => 'Crear línea',
        'marcas'     => 'Crear marca',
        'temporadas' => 'Hay dos temporadas por año',
        'productos'  => 'Publicar producto',
        'colores'    => 'Agregar color',
    ];

    public function test_cada_pestana_enseña_lo_suyo_y_no_lo_de_las_otras(): void
    {
        foreach (self::SUYO as $pestana => $propio) {
            $vista = $this->panel()->set('pestana', $pestana);

            $vista->assertSee($propio);

            foreach (self::SUYO as $otra => $ajeno) {
                if ($otra !== $pestana) {
                    $vista->assertDontSee($ajeno);
                }
            }
        }
    }

    /** El caso exacto que se reportó. */
    public function test_en_colores_no_salen_los_productos(): void
    {
        $this->panel()
            ->set('pestana', 'colores')
            ->assertSee('Agregar color')
            ->assertSee('Marino')
            ->assertDontSee('Publicar producto')
            ->assertDontSeeHtml('wire:click="abrirFotos(');
    }

    /** Y al revés, que al arreglarlo no se rompiera la de productos. */
    public function test_en_productos_no_sale_la_paleta(): void
    {
        $this->panel()
            ->set('pestana', 'productos')
            ->assertSee('Publicar producto')
            ->assertDontSee('Agregar color');
    }
}
