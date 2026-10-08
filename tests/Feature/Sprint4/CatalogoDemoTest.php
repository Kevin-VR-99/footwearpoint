<?php

namespace Tests\Feature\Sprint4;

use App\Models\Campana;
use App\Models\Distribuidora;
use App\Models\DistribuidoraLinea;
use App\Models\Linea;
use App\Models\OfertaDistribuidora;
use App\Models\ProductoCampana;
use App\Models\ProductoImagen;
use App\Models\Talla;
use App\Services\Catalogo\CatalogoVisible;
use App\Services\Catalogo\PrecioEfectivo;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\DemoDistribuidoraSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TG-217 (Ola 2, K11) — El catálogo de demostración.
 *
 * Lo que tiene que quedar después de sembrar: un catálogo maestro con datos de
 * catálogos reales, y dos distribuidoras que venden pedazos distintos de ese
 * mismo catálogo, con sus propios precios.
 *
 * No se revisan precios ni modelos uno por uno: eso cambia cada vez que el
 * negocio manda otro catálogo. Se revisa que la demo cuente bien la historia.
 */
class CatalogoDemoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    public function test_el_catalogo_maestro_tiene_tres_lineas_con_una_sola_temporada_activa(): void
    {
        $lineas = Linea::all();

        $this->assertCount(3, $lineas);

        foreach ($lineas as $linea) {
            $activas = Campana::where('linea_id', $linea->id)->where('estado', 'activa')->count();

            $this->assertSame(1, $activas, "La línea {$linea->nombre} no tiene exactamente una temporada activa");
        }

        // Una temporada que ya pasó, para que no todo se vea igual de nuevo.
        $this->assertTrue(Campana::where('estado', 'finalizada')->exists());
    }

    public function test_el_catalogo_tiene_productos_de_dama_y_tallas_para_ellos(): void
    {
        $this->assertTrue(Talla::where('sistema', 'MX')->whereIn('valor', ['2', '7'])->count() === 2);

        $confort = Linea::where('nombre', 'Confort Dama')->firstOrFail();

        $this->assertTrue(
            ProductoCampana::whereRelation('campana', 'linea_id', $confort->id)->exists()
        );
    }

    public function test_la_distribuidora_demo_vende_dos_lineas_y_no_la_tercera(): void
    {
        $this->comoLaDistribuidora(DemoDistribuidoraSeeder::SLUG, function () {
            $suyas = DistribuidoraLinea::query()->vigentes()->count();

            $this->assertSame(2, $suyas);
            $this->assertLessThan(Linea::count(), $suyas, 'La demo tendría que dejar una línea sin activar');

            // Ninguna le cuesta extra: caben en las que incluye su plan.
            $this->assertSame(0, DistribuidoraLinea::where('es_extra', true)->count());
        });
    }

    public function test_la_demo_tiene_su_descuento_un_precio_propio_y_un_producto_oculto(): void
    {
        $this->comoLaDistribuidora(DemoDistribuidoraSeeder::SLUG, function () {
            $precios = new PrecioEfectivo;

            $this->assertGreaterThan(0, $precios->descuentoMayorista());

            $catalogo = new CatalogoVisible;
            $visibles = $catalogo->consulta()->pluck('producto_campana.id');

            $this->assertNotEmpty($visibles);

            // El producto que escondió es suyo (de una línea que vende) y aun
            // así no aparece: es lo que hace la oferta de K5.
            $ocultos = ProductoCampana::query()
                ->whereRelation('campana', 'estado', 'activa')
                ->whereNotIn('producto_campana.id', $visibles)
                ->whereIn(
                    'campana_id',
                    Campana::whereIn('linea_id', DistribuidoraLinea::query()->vigentes()->select('linea_id'))->select('id')
                )
                ->count();

            $this->assertSame(1, $ocultos);

            // Y hay un producto con precio propio: su mayoreo no sale del
            // descuento general.
            $conPrecioPropio = ProductoCampana::query()
                ->whereKey($visibles)
                ->get()
                ->filter(fn (ProductoCampana $p) => $precios->mayoreo($p) !== $this->conDescuento($p, $precios));

            $this->assertCount(1, $conPrecioPropio);
        });
    }

    public function test_la_segunda_distribuidora_le_pone_otro_precio_al_mismo_producto(): void
    {
        // El producto al que las dos le pusieron precio propio.
        $productoCampanaId = OfertaDistribuidora::withoutGlobalScopes()
            ->whereNotNull('precio_mayorista')
            ->groupBy('producto_campana_id')
            ->havingRaw('COUNT(*) > 1')
            ->value('producto_campana_id');

        $this->assertNotNull($productoCampanaId, 'Ninguna distribuidora comparte producto con precio propio');

        $modelo = ProductoCampana::findOrFail($productoCampanaId);

        $primera = $this->comoLaDistribuidora(
            DemoDistribuidoraSeeder::SLUG,
            fn () => (new PrecioEfectivo)->mayoreo($modelo)
        );

        $segunda = $this->comoLaDistribuidora(
            DemoDistribuidoraSeeder::SEGUNDA_SLUG,
            fn () => (new PrecioEfectivo)->mayoreo($modelo)
        );

        $this->assertNotEquals($primera, $segunda, 'Las dos distribuidoras venderían al mismo precio de mayoreo');

        // El de menudeo sí es el mismo para las dos: es del catálogo (D8).
        $this->assertSame(
            round((float) $modelo->precio_publico, 2),
            (new PrecioEfectivo)->menudeo($modelo)
        );
    }

    /** En pruebas nunca se suben fotos: no se depende de la red ni del bucket. */
    public function test_las_pruebas_no_suben_las_fotos_de_la_demo(): void
    {
        $this->assertSame(0, ProductoImagen::count());
    }

    private function conDescuento(ProductoCampana $producto, PrecioEfectivo $precios): float
    {
        return round((float) $producto->precio_publico * (1 - $precios->descuentoMayorista() / 100), 2);
    }

    /** @return mixed */
    private function comoLaDistribuidora(string $slug, callable $hacer)
    {
        $distribuidora = Distribuidora::where('slug', $slug)->firstOrFail();

        return Tenant::forzar($distribuidora->id, $hacer);
    }
}
