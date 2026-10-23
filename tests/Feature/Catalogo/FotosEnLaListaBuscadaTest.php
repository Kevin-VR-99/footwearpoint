<?php

namespace Tests\Feature\Catalogo;

use App\Models\ProductoCampana;
use App\Models\Usuario;
use App\Services\Catalogo\GestionarImagenProductoCampanaAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\AdminGeneralSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TG-282 — La miniatura de la lista cuando se busca.
 *
 * Reportado al revisar el Cklass en producción: al buscar "DÚO #613" en el
 * catálogo maestro el renglón salía sin foto, aunque la ficha del producto
 * sí la enseñaba.
 *
 * La causa está en el cruce de dos cosas que llegaron por separado: la tabla
 * dibuja productosFiltrados (el buscador), pero las fotos se pedían para
 * productos, que son las últimas 50 publicaciones. Buscar algo más viejo que
 * esas 50 daba un renglón sin miniatura.
 */
class FotosEnLaListaBuscadaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);
    }

    /**
     * Hacen falta más de 50 publicaciones para que esto se note: con menos,
     * la lista sin filtrar ya las trae todas y el fallo no aparece. La
     * primera versión de esta prueba no las creaba y pasaba con el fallo
     * puesto, que es como no tener prueba.
     */
    private function llenarLaLista(): void
    {
        $base = ProductoCampana::withoutGlobalScopes()->orderBy('id')->firstOrFail();

        for ($i = 0; $i < 55; $i++) {
            $producto = \App\Models\Producto::create([
                'marca_id'     => $base->producto->marca_id,
                'categoria_id' => $base->producto->categoria_id,
                'modelo'       => 'RELLENO-'.$i,
                'nombre'       => 'Relleno '.$i,
                'activo'       => true,
            ]);

            ProductoCampana::create([
                'producto_id'     => $producto->id,
                'campana_id'      => $base->campana_id,
                'codigo_catalogo' => 'RELL-'.$i,
                'precio_publico'  => 499.0,
                'activo'          => true,
            ]);
        }
    }

    public function test_al_buscar_un_producto_viejo_su_miniatura_aparece(): void
    {
        // El más viejo: el que queda fuera de las últimas 50.
        $viejo = ProductoCampana::withoutGlobalScopes()->orderBy('id')->firstOrFail();

        $this->llenarLaLista();

        app(GestionarImagenProductoCampanaAction::class)
            ->agregar($viejo, UploadedFile::fake()->image('duo613.jpg', 800, 600));

        $panel = Livewire::actingAs(Usuario::where('email', AdminGeneralSeeder::EMAIL)->firstOrFail())
            ->test('admin.catalogo-maestro')
            ->set('pestana', 'productos')
            ->set('buscarProducto', $viejo->codigo_catalogo);

        $fotos = $panel->instance()->fotos;

        $this->assertArrayHasKey(
            $viejo->id,
            $fotos,
            'La publicación buscada tiene foto, pero la lista no la pidió.',
        );
        $this->assertNotEmpty($fotos[$viejo->id]);
    }

    /** Sin buscar nada, la lista normal sigue trayendo sus fotos. */
    public function test_sin_buscar_la_lista_sigue_igual(): void
    {
        $reciente = ProductoCampana::withoutGlobalScopes()->orderByDesc('id')->firstOrFail();

        app(GestionarImagenProductoCampanaAction::class)
            ->agregar($reciente, UploadedFile::fake()->image('reciente.jpg', 800, 600));

        $fotos = Livewire::actingAs(Usuario::where('email', AdminGeneralSeeder::EMAIL)->firstOrFail())
            ->test('admin.catalogo-maestro')
            ->set('pestana', 'productos')
            ->instance()
            ->fotos;

        $this->assertArrayHasKey($reciente->id, $fotos);
    }
}
