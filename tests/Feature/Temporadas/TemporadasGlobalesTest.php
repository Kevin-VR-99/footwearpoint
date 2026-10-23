<?php

namespace Tests\Feature\Temporadas;

use App\Models\Campana;
use App\Models\CategoriaProducto;
use App\Models\DistribuidoraStaff;
use App\Models\ImportacionCatalogo;
use App\Models\Linea;
use App\Models\Marca;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\Producto;
use App\Models\ProductoCampana;
use App\Models\RevendedorDistribuidora;
use App\Models\Sucursal;
use App\Models\Temporada;
use App\Models\Usuario;
use App\Models\Variante;
use App\Services\Catalogo\CampanaDeTemporada;
use App\Services\Catalogo\CatalogoVisible;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Temporadas globales: dos por año (Primavera-Verano del 1 de enero al 30 de
 * junio y Otoño-Invierno del 1 de julio al 31 de diciembre), iguales para
 * todas las líneas, y cambian solas por fecha en la zona de México.
 */
class TemporadasGlobalesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const ADMIN = 'admin@calzadosramirez.test';

    private function como(string $email): Usuario
    {
        $this->app['auth']->forgetGuards();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        $usuario = Usuario::where('email', $email)->firstOrFail();
        $this->actingAs($usuario);
        Tenant::olvidarCache();

        return $usuario;
    }

    /** Un instante dado en UTC (como lo guarda el servidor). */
    private function enUtc(string $fecha): void
    {
        $this->travelTo(CarbonImmutable::parse($fecha, 'UTC'));
    }

    private function publicar(Campana $campana, string $nombre): ProductoCampana
    {
        $producto = Producto::create([
            'marca_id' => Marca::firstOrFail()->id,
            'categoria_id' => CategoriaProducto::firstOrFail()->id,
            'modelo' => 'TMP-'.uniqid(),
            'nombre' => $nombre,
            'activo' => true,
        ]);

        return ProductoCampana::create([
            'producto_id' => $producto->id,
            'campana_id' => $campana->id,
            'codigo_catalogo' => 'TMP-'.uniqid(),
            'precio_publico' => 450,
            'activo' => true,
        ]);
    }

    /** Un producto que la distribuidora vende hoy. */
    private function visibleHoy(): ProductoCampana
    {
        return app(CatalogoVisible::class)->consulta()->with('campana')->firstOrFail();
    }

    // ------------------------------------------------------------------
    // Cambio por fecha
    // ------------------------------------------------------------------

    public function test_el_30_de_junio_es_primavera_verano_y_el_1_de_julio_otono_invierno(): void
    {
        $this->enUtc('2026-06-30 12:00:00');
        $this->assertSame('Primavera-Verano 2026', Temporada::actual()->nombre);
        $this->assertSame('2026-01-01', Temporada::actual()->fecha_inicio->toDateString());
        $this->assertSame('2026-06-30', Temporada::actual()->fecha_fin->toDateString());

        $this->enUtc('2026-07-01 12:00:00');
        $this->assertSame('Otoño-Invierno 2026', Temporada::actual()->nombre);
        $this->assertSame('2026-07-01', Temporada::actual()->fecha_inicio->toDateString());
        $this->assertSame('2026-12-31', Temporada::actual()->fecha_fin->toDateString());
    }

    public function test_el_31_de_diciembre_es_otono_invierno_y_el_1_de_enero_primavera_verano(): void
    {
        $this->enUtc('2026-12-31 12:00:00');
        $this->assertSame('Otoño-Invierno 2026', Temporada::actual()->nombre);

        $this->enUtc('2027-01-01 12:00:00');
        $this->assertSame('Primavera-Verano 2027', Temporada::actual()->nombre);
        $this->assertSame('Otoño-Invierno 2027', Temporada::siguiente()->nombre);
    }

    /** México es UTC-6 todo el año: el cambio es a las 06:00 UTC, no a medianoche UTC. */
    public function test_el_cambio_es_a_medianoche_de_la_ciudad_de_mexico_y_no_de_utc(): void
    {
        $this->enUtc('2026-07-01 05:59:59'); // 30 de junio, 23:59:59 en CDMX
        $this->assertSame('Primavera-Verano 2026', Temporada::actual()->nombre);

        $this->enUtc('2026-07-01 06:00:00'); // 1 de julio, 00:00 en CDMX
        $this->assertSame('Otoño-Invierno 2026', Temporada::actual()->nombre);

        $this->enUtc('2027-01-01 05:59:59'); // 31 de diciembre, 23:59:59 en CDMX
        $this->assertSame('Otoño-Invierno 2026', Temporada::actual()->nombre);

        $this->enUtc('2027-01-01 06:00:00');
        $this->assertSame('Primavera-Verano 2027', Temporada::actual()->nombre);
    }

    public function test_solo_hay_un_renglon_por_temporada_y_se_audita_al_crearse(): void
    {
        $this->enUtc('2031-03-10 12:00:00');
        $primera = Temporada::actual();
        $this->assertSame($primera->id, Temporada::actual()->id);
        $this->assertSame(1, Temporada::where('nombre', 'Primavera-Verano 2031')->count());
        $this->assertDatabaseHas('auditorias', ['accion' => 'catalogo.temporada_global.creada', 'entidad_id' => $primera->id]);
    }

    // ------------------------------------------------------------------
    // Visibilidad
    // ------------------------------------------------------------------

    public function test_la_proxima_temporada_queda_oculta_hasta_su_fecha_y_la_anterior_se_oculta(): void
    {
        $this->como(self::ADMIN);
        $actual = $this->visibleHoy();
        $siguiente = Temporada::siguiente();
        $campanaFutura = app(CampanaDeTemporada::class)->paraLinea($actual->campana->linea_id, $siguiente);
        $futuro = $this->publicar($campanaFutura, 'Bota de la próxima temporada');

        $visible = app(CatalogoVisible::class);
        $this->assertTrue($visible->puedeVender($actual->id));
        $this->assertFalse($visible->puedeVender($futuro->id));
        $this->assertSame('borrador', $campanaFutura->fresh()->estadoPorFecha());

        // Medianoche (CDMX) del primer día de la siguiente temporada.
        $this->travelTo(CarbonImmutable::parse($siguiente->fecha_inicio->toDateString().' 00:00:00', Temporada::ZONA));
        Tenant::olvidarCache();

        $this->assertTrue($visible->puedeVender($futuro->id));
        $this->assertFalse($visible->puedeVender($actual->id));
        $this->assertSame('activa', $campanaFutura->fresh()->estadoPorFecha());
        $this->assertSame('finalizada', $actual->campana->fresh()->estadoPorFecha());
    }

    public function test_la_tienda_publica_y_la_api_no_muestran_la_proxima_temporada(): void
    {
        $usuario = $this->como(self::ADMIN);
        $actual = $this->visibleHoy();
        $campanaFutura = app(CampanaDeTemporada::class)->paraLinea($actual->campana->linea_id, Temporada::siguiente());
        $this->publicar($campanaFutura, 'Sandalia secreta próxima');

        \Laravel\Sanctum\Sanctum::actingAs($usuario);
        $respuesta = $this->getJson('/api/catalogo');
        if ($respuesta->status() === 200) {
            $respuesta->assertDontSee('Sandalia secreta próxima');
        }

        $this->assertSame(0, app(CatalogoVisible::class)->consulta()
            ->whereHas('producto', fn ($q) => $q->where('nombre', 'Sandalia secreta próxima'))->count());
    }

    public function test_los_pedidos_de_una_temporada_pasada_siguen_validos(): void
    {
        $this->como(self::ADMIN);
        $pc = $this->visibleHoy();

        $pedido = Pedido::create([
            'distribuidora_id' => Tenant::id(),
            'sucursal_id' => Sucursal::query()->value('id'),
            'folio' => 'TMP-'.uniqid(),
            'tipo' => 'revendedor',
            'revendedor_distribuidora_id' => RevendedorDistribuidora::query()->value('id'),
            'estado' => 'colocado',
            'subtotal' => 900,
            'total' => 900,
            'fecha_colocacion' => now(),
            'capturado_por_staff_id' => (int) DistribuidoraStaff::query()->value('id'),
        ]);
        PedidoDetalle::query()->forceCreate([
            'distribuidora_id' => Tenant::id(), 'pedido_id' => $pedido->id,
            'producto_campana_id' => $pc->id, 'variante_id' => Variante::query()->value('id'),
            'producto_nombre' => 'x', 'modelo' => 'x', 'talla' => '25', 'color' => 'Negro',
            'cantidad' => 2, 'precio_unitario' => 450, 'subtotal' => 900,
        ]);

        $siguiente = Temporada::siguiente();
        $this->travelTo(CarbonImmutable::parse($siguiente->fecha_fin->toDateString().' 12:00:00', Temporada::ZONA));
        Tenant::olvidarCache();

        $pedido = Pedido::with('detalle.productoCampana.campana')->findOrFail($pedido->id);
        $this->assertSame('colocado', $pedido->estado);
        $this->assertCount(1, $pedido->detalle);
        $this->assertSame($pc->id, $pedido->detalle->first()->productoCampana->id);
        $this->assertSame('finalizada', $pedido->detalle->first()->productoCampana->campana->estadoPorFecha());
        $this->assertDatabaseHas('producto_campana', ['id' => $pc->id, 'activo' => true]);
    }

    // ------------------------------------------------------------------
    // Importar a la próxima temporada
    // ------------------------------------------------------------------

    public function test_importar_a_la_proxima_temporada_crea_su_campana_oculta_y_se_audita(): void
    {
        $admin = Usuario::where('email', \Database\Seeders\AdminGeneralSeeder::EMAIL)->firstOrFail();
        setPermissionsTeamId(0);
        $this->actingAs($admin);
        \Illuminate\Support\Facades\Storage::fake('catalogos_de_prueba');
        config(['filesystems.catalogos_disk' => 'catalogos_de_prueba']);

        $linea = Linea::create(['nombre' => 'Línea nueva de prueba', 'activa' => true]);
        $siguiente = Temporada::siguiente();

        \Livewire\Livewire::test('admin.catalogos-importaciones')
            ->set('linea_id', $linea->id)
            ->set('temporada_id', $siguiente->id)
            ->set('archivo', \Illuminate\Http\UploadedFile::fake()->create('proxima.pdf', 300, 'application/pdf'))
            ->call('subir')
            ->assertHasNoErrors();

        $campana = ImportacionCatalogo::latest('id')->firstOrFail()->campana;
        $this->assertSame($linea->id, (int) $campana->linea_id);
        $this->assertSame($siguiente->id, (int) $campana->temporada_id);
        $this->assertSame($siguiente->nombre, $campana->nombre);
        $this->assertFalse(Campana::vigentes()->whereKey($campana->id)->exists());
        $this->assertDatabaseHas('auditorias', ['accion' => 'catalogo.temporada.creada_automaticamente', 'entidad_id' => $campana->id]);

        // Volver a importar a la misma temporada usa la misma campaña.
        $this->assertSame($campana->id, app(CampanaDeTemporada::class)->paraLinea($linea->id, $siguiente)->id);
    }

    public function test_la_lista_de_temporadas_del_admin_es_de_solo_lectura(): void
    {
        $admin = Usuario::where('email', \Database\Seeders\AdminGeneralSeeder::EMAIL)->firstOrFail();
        setPermissionsTeamId(0);
        $this->actingAs($admin);
        Tenant::olvidarCache();

        \Livewire\Livewire::test('admin.catalogo-maestro')
            ->set('pestana', 'temporadas')
            ->assertSee(Temporada::actual()->nombre)
            ->assertSee(Temporada::siguiente()->nombre)
            ->assertSee('Actual')
            ->assertSee('Próxima')
            ->assertDontSee('Crear temporada');
    }

    public function test_la_api_ya_no_crea_ni_edita_ni_borra_temporadas_y_sigue_listando(): void
    {
        $admin = Usuario::where('email', \Database\Seeders\AdminGeneralSeeder::EMAIL)->firstOrFail();
        \Laravel\Sanctum\Sanctum::actingAs($admin);
        Tenant::olvidarCache();
        $campana = Campana::firstOrFail();

        $this->postJson('/api/campanas', ['linea_id' => $campana->linea_id, 'nombre' => 'X'])->assertStatus(405);
        $this->patchJson("/api/campanas/{$campana->id}", ['nombre' => 'X'])->assertStatus(405);
        $this->deleteJson("/api/campanas/{$campana->id}")->assertStatus(405);
        $this->assertDatabaseHas('campanas', ['id' => $campana->id, 'nombre' => $campana->nombre]);

        $this->getJson("/api/campanas/{$campana->id}")->assertOk()
            ->assertJsonPath('data.temporada_id', $campana->temporada_id)
            ->assertJsonPath('data.estado', $campana->estadoPorFecha());
    }

    // ------------------------------------------------------------------
    // Migración de datos (campañas 1–4 de producción)
    // ------------------------------------------------------------------

    public function test_la_migracion_unifica_las_campanas_como_en_produccion_y_es_idempotente(): void
    {
        $migracion = require database_path('migrations/2026_10_27_100000_unifica_temporadas_globales.php');

        // Renglón provisional: los datos viejos aún no tienen temporada.
        $provisional = DB::table('temporadas')->insertGetId([
            'nombre' => 'Sin asignar', 'fecha_inicio' => '1990-01-01', 'fecha_fin' => '1990-06-30',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $linea = fn (string $n) => Linea::create(['nombre' => $n, 'activa' => true])->id;
        $vieja = fn (int $lineaId, string $nombre, string $estado, ?string $inicio = null) => DB::table('campanas')->insertGetId([
            'linea_id' => $lineaId, 'temporada_id' => $provisional, 'nombre' => $nombre, 'estado' => $estado,
            'fecha_inicio' => $inicio, 'fecha_fin' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $cklass = $vieja($linea('Cklass prueba'), 'Cklass Otoño-Invierno 2026', 'activa', '2026-09-01');
        $finalizada = $vieja($linea('Impuls PV prueba'), 'Impuls Primavera-Verano 2026', 'finalizada', '2026-01-15');
        $impuls = $vieja($linea('Impuls prueba'), 'Impuls Otoño-Invierno 2026', 'activa');
        $dankriz = $vieja($linea('Dankriz prueba'), 'Dankriz Temporada 1', 'borrador', '2026-08-01');

        $productos = [];
        for ($i = 0; $i < 3; $i++) {
            $productos[] = $this->publicar(Campana::findOrFail($cklass), "Cklass {$i}")->id;
        }
        $imagenesAntes = DB::table('producto_imagenes')->count();

        $migracion->up();

        $oi = Temporada::where('fecha_inicio', '2026-07-01')->firstOrFail();
        $pv = Temporada::where('fecha_inicio', '2026-01-01')->firstOrFail();
        $this->assertEquals(
            ['temporada_id' => $oi->id, 'nombre' => 'Otoño-Invierno 2026', 'fecha_inicio' => '2026-07-01', 'fecha_fin' => '2026-12-31'],
            (array) DB::table('campanas')->where('id', $cklass)->first(['temporada_id', 'nombre', 'fecha_inicio', 'fecha_fin'])
        );
        $this->assertSame('Otoño-Invierno 2026', $oi->nombre);
        $this->assertSame($pv->id, (int) DB::table('campanas')->where('id', $finalizada)->value('temporada_id'));
        $this->assertSame('Primavera-Verano 2026', DB::table('campanas')->where('id', $finalizada)->value('nombre'));
        $this->assertDatabaseMissing('campanas', ['id' => $impuls]);
        $this->assertDatabaseMissing('campanas', ['id' => $dankriz]);
        $this->assertSame(3, DB::table('producto_campana')->whereIn('id', $productos)->where('campana_id', $cklass)->count());
        $this->assertSame($imagenesAntes, DB::table('producto_imagenes')->count());
        $this->assertDatabaseHas('auditorias', ['accion' => 'catalogo.temporada.unificada', 'entidad_id' => $cklass]);
        $this->assertDatabaseHas('auditorias', ['accion' => 'catalogo.temporada.eliminada', 'entidad_id' => $impuls]);
        $this->assertDatabaseHas('auditorias', ['accion' => 'catalogo.temporada.eliminada', 'entidad_id' => $dankriz]);

        // Correrla otra vez no cambia nada ni deja más auditoría.
        $campanas = DB::table('campanas')->orderBy('id')->get()->map(fn ($c) => (array) $c)->all();
        $auditorias = DB::table('auditorias')->count();
        $migracion->up();
        $this->assertEquals($campanas, DB::table('campanas')->orderBy('id')->get()->map(fn ($c) => (array) $c)->all());
        $this->assertSame($auditorias, DB::table('auditorias')->count());
    }

    public function test_la_migracion_no_borra_una_campana_duplicada_con_productos(): void
    {
        $migracion = require database_path('migrations/2026_10_27_100000_unifica_temporadas_globales.php');
        $provisional = DB::table('temporadas')->insertGetId([
            'nombre' => 'Sin asignar', 'fecha_inicio' => '1990-01-01', 'fecha_fin' => '1990-06-30',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $otra = DB::table('temporadas')->insertGetId([
            'nombre' => 'Sin asignar 2', 'fecha_inicio' => '1990-07-01', 'fecha_fin' => '1990-12-31',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $lineaId = Linea::create(['nombre' => 'Línea duplicada', 'activa' => true])->id;
        $ids = [];
        foreach ([[$provisional, 'Cklass Otoño-Invierno 2026'], [$otra, 'Cklass OI 2026 bis']] as [$t, $nombre]) {
            $ids[] = DB::table('campanas')->insertGetId([
                'linea_id' => $lineaId, 'temporada_id' => $t, 'nombre' => $nombre, 'estado' => 'activa',
                'fecha_inicio' => '2026-08-01', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach ($ids as $id) {
            $this->publicar(Campana::findOrFail($id), 'Con producto '.$id);
        }

        try {
            $migracion->up();
            $this->fail('Unió dos campañas con productos sin avisar.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Hay que unirlas a mano', $e->getMessage());
        }

        $this->assertSame(2, DB::table('campanas')->whereIn('id', $ids)->count());
    }
}
