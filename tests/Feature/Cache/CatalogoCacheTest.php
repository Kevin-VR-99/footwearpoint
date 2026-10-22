<?php

namespace Tests\Feature\Cache;

use App\Models\Campana;
use App\Models\CicloCompra;
use App\Models\Color;
use App\Models\DistribuidoraLinea;
use App\Models\Marca;
use App\Models\Talla;
use App\Models\Usuario;
use App\Support\Cache\CatalogoCache;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Caché de catálogos por distribuidora: llaves con tenant, aislamiento e invalidación. */
class CatalogoCacheTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const ADMIN_A = 'admin@calzadosramirez.test';
    private const ADMIN_B = 'admin@boutiquedelcalzado.test';

    private function nuevoCiclo(int $distribuidoraId, string $nombre): CicloCompra
    {
        $ciclo = new CicloCompra();
        $ciclo->forceFill([
            'distribuidora_id' => $distribuidoraId,
            'nombre' => $nombre,
            'fecha_apertura' => now()->subDays(20),
            'fecha_cierre' => now()->subDays(10),
            'estado' => 'finalizado',
        ])->save();

        return $ciclo;
    }

    private function como(string $email): Usuario
    {
        $this->app['auth']->forgetGuards();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        $usuario = Usuario::where('email', $email)->firstOrFail();
        $this->actingAs($usuario);
        Tenant::olvidarCache();
        $this->olvidarMemo();

        return $usuario;
    }

    /** Cache::memo() vive por petición; en la prueba se suelta a mano. */
    private function olvidarMemo(): void
    {
        $this->app->forgetInstance('cache.__memoized:'.config('cache.default'));
    }

    private function cache(): CatalogoCache
    {
        return app(CatalogoCache::class);
    }

    private function consultas(callable $fn): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $fn();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    public function test_la_llave_siempre_lleva_la_distribuidora(): void
    {
        $this->como(self::ADMIN_A);
        $this->cache()->ciclos();

        $this->assertTrue(Cache::has(CatalogoCache::llave(Tenant::id(), 'ciclos')));
        $this->assertSame('catalogo:7:marcas', CatalogoCache::llave(7, 'marcas'));
        $this->assertSame('catalogo:0:marcas', CatalogoCache::llave(null, 'marcas'));
    }

    public function test_la_segunda_lectura_no_consulta_la_base(): void
    {
        $this->como(self::ADMIN_A);
        $this->cache()->marcas();
        $this->cache()->tallas();

        $this->olvidarMemo();
        $this->assertSame(0, $this->consultas(fn () => [$this->cache()->marcas(), $this->cache()->tallas(), $this->cache()->colores()]));
    }

    public function test_los_ciclos_de_una_distribuidora_no_se_ven_en_otra(): void
    {
        $this->como(self::ADMIN_A);
        $idA = Tenant::id();
        $this->nuevoCiclo($idA, 'Ciclo A');
        $ciclosA = $this->cache()->ciclos()->pluck('id')->all();

        $this->como(self::ADMIN_B);
        $idB = Tenant::id();
        $this->assertNotSame($idA, $idB);
        $this->nuevoCiclo($idB, 'Ciclo B');
        $ciclosB = $this->cache()->ciclos()->pluck('id')->all();

        $this->assertNotEmpty($ciclosA);
        $this->assertNotEmpty($ciclosB);
        $this->assertSame([], array_intersect($ciclosA, $ciclosB));
        $this->assertNotContains('Ciclo A', $this->cache()->ciclos()->pluck('nombre')->all());
        $this->assertEqualsCanonicalizing(
            CicloCompra::withoutGlobalScopes()->where('distribuidora_id', $idB)->pluck('id')->all(),
            $ciclosB
        );
    }

    public function test_crear_un_ciclo_limpia_solo_la_cache_de_su_distribuidora(): void
    {
        $this->como(self::ADMIN_B);
        $idB = Tenant::id();
        $this->cache()->ciclos();

        $this->como(self::ADMIN_A);
        $idA = Tenant::id();
        $antes = $this->cache()->ciclos()->count();

        $this->nuevoCiclo($idA, 'Ciclo de prueba caché');

        $this->assertFalse(Cache::has(CatalogoCache::llave($idA, 'ciclos')));
        $this->assertTrue(Cache::has(CatalogoCache::llave($idB, 'ciclos')), 'La caché de la otra distribuidora no se toca.');

        $this->olvidarMemo();
        $this->assertSame($antes + 1, $this->cache()->ciclos()->count());
        $this->assertContains('Ciclo de prueba caché', $this->cache()->ciclos()->pluck('nombre')->all());
    }

    public function test_cambiar_una_marca_o_talla_limpia_la_cache_de_todas_las_distribuidoras(): void
    {
        $this->como(self::ADMIN_A);
        $idA = Tenant::id();
        $this->cache()->marcas();
        $this->cache()->tallas();
        $this->como(self::ADMIN_B);
        $idB = Tenant::id();
        $this->cache()->marcas();
        $this->cache()->tallas();

        $marca = Marca::query()->firstOrFail();
        $marca->update(['nombre' => 'Marca Renombrada']);

        foreach ([$idA, $idB] as $id) {
            $this->assertFalse(Cache::has(CatalogoCache::llave($id, 'marcas')));
            $this->assertTrue(Cache::has(CatalogoCache::llave($id, 'tallas_colores')));
        }
        $this->assertContains('Marca Renombrada', $this->cache()->marcas()->pluck('nombre')->all());

        $talla = Talla::query()->firstOrFail();
        $talla->update(['valor' => '99']);
        $this->assertFalse(Cache::has(CatalogoCache::llave($idA, 'tallas_colores')));
        $this->olvidarMemo();
        $this->assertSame('99', (string) $this->cache()->tallas()[$talla->id]['valor']);

        Color::query()->create(['nombre' => 'Color nuevo de prueba'])->delete();
        $this->assertFalse(Cache::has(CatalogoCache::llave($idB, 'tallas_colores')));
    }

    public function test_las_temporadas_del_filtro_se_limpian_al_cambiar_una_temporada_de_sus_lineas(): void
    {
        $this->como(self::ADMIN_A);
        $idA = Tenant::id();
        $temporadas = $this->cache()->temporadasParaFiltro();
        $this->assertNotEmpty($temporadas);
        $this->assertNotNull($temporadas->first()->linea?->nombre);

        $campana = Campana::query()->whereKey($temporadas->first()->id)->firstOrFail();
        $campana->nombre = 'Temporada renombrada';
        $campana->save();

        $this->assertFalse(Cache::has(CatalogoCache::llave($idA, 'temporadas_filtro')));
        $this->olvidarMemo();
        $this->assertContains('Temporada renombrada', $this->cache()->temporadasParaFiltro()->pluck('nombre')->all());
    }

    public function test_las_temporadas_respetan_las_lineas_de_cada_distribuidora(): void
    {
        foreach ([self::ADMIN_A, self::ADMIN_B] as $email) {
            $this->como($email);
            $lineas = DistribuidoraLinea::withoutGlobalScopes()->where('distribuidora_id', Tenant::id())->pluck('linea_id')->all();
            foreach ($this->cache()->temporadasParaFiltro() as $t) {
                $this->assertContains($t->linea_id, $lineas);
            }
        }
    }

    public function test_stock_y_punto_de_venta_siguen_mostrando_talla_y_color(): void
    {
        $this->como(self::ADMIN_A);

        $this->get('/stock')->assertOk();
        $this->get('/punto-venta')->assertOk();
        $this->get('/reportes')->assertOk();
    }
}
