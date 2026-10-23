<?php

namespace Tests\Feature\Pruebas;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LineasMarcasSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** db:seed en producción no vuelve a crear el catálogo ni las distribuidoras demo. */
class SeedersDemoEnProduccionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        putenv('SEED_PASSWORD');
        unset($_ENV['SEED_PASSWORD'], $_SERVER['SEED_PASSWORD']);

        parent::tearDown();
    }

    private function enProduccion(): void
    {
        $this->app['env'] = 'production';
        // Producción exige SEED_PASSWORD para las cuentas de prueba (TG-186).
        putenv('SEED_PASSWORD=secreto-de-prueba');
        $_ENV['SEED_PASSWORD'] = $_SERVER['SEED_PASSWORD'] = 'secreto-de-prueba';
    }

    /**
     * Si otra clase de la suite ya sembró la base (RefreshDatabase migra una
     * sola vez por proceso), se vacían las tablas demo dentro de la
     * transacción de la prueba; al terminar se revierte.
     */
    private function sinDatosDemo(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['producto_campana', 'productos', 'pedidos', 'distribuidoras'] as $tabla) {
            DB::table($tabla)->delete();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function test_en_produccion_db_seed_no_crea_datos_demo(): void
    {
        $this->enProduccion();
        $this->sinDatosDemo();

        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();

        $this->assertSame(0, DB::table('producto_campana')->count());
        $this->assertSame(0, DB::table('productos')->count());
        $this->assertSame(0, DB::table('distribuidoras')->count());
        $this->assertSame(0, DB::table('pedidos')->count());
        $this->assertGreaterThan(0, DB::table('lineas')->count());
        $this->assertGreaterThan(0, DB::table('marcas')->count());
    }

    public function test_lineas_marcas_seeder_sigue_corriendo_en_produccion(): void
    {
        $this->enProduccion();

        $this->artisan('db:seed', ['--class' => LineasMarcasSeeder::class, '--force' => true])->assertSuccessful();

        $this->assertGreaterThan(0, DB::table('linea_marca')->count());
    }

    public function test_con_la_variable_explicita_si_siembra_la_demo_en_produccion(): void
    {
        $this->enProduccion();
        config(['app.seed_demo_en_produccion' => true]);

        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();

        $this->assertGreaterThan(0, DB::table('producto_campana')->count());
    }

    public function test_fuera_de_produccion_siembra_como_siempre(): void
    {
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();

        $this->assertGreaterThan(0, DB::table('producto_campana')->count());
        $this->assertGreaterThan(0, DB::table('distribuidoras')->count());
    }
}
