<?php

namespace Tests\Feature\Auditoria;

use App\Jobs\AprobarCatalogoImportado;
use App\Models\Auditoria;
use App\Models\Campana;
use App\Models\CategoriaProducto;
use App\Models\Color;
use App\Models\ImportacionCatalogo;
use App\Models\Marca;
use App\Models\ProductoImportadoStaging;
use App\Models\Usuario;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * TG-377 — La auditoría de lo que hace el worker dice de dónde vino.
 *
 * LO QUE PASÓ. El 27-nov-2026 se aprobó en producción el catálogo Impuls
 * (26 productos) desde el panel. Las 300+ filas de auditoría que generó
 * quedaron con `ip_origen = 127.0.0.1`, mientras que los inicios de sesión
 * del mismo día guardaron la IP de verdad, 84.17.44.227.
 *
 * La causa: aprobar se fue a la cola, y en el worker `request()->ip()` es la
 * del propio servidor. El QUIÉN estaba bien (Auth::onceUsingId ya lo
 * resolvía); el DESDE DÓNDE mentía, y un dato falso en la auditoría es peor
 * que uno vacío, porque parece real.
 */
class AuditarIpDesdeLaColaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const IP_DE_QUIEN_PIDIO = '84.17.44.227';

    private ImportacionCatalogo $importacion;

    private int $campanaId;

    private int $categoriaId;

    private int $marcaId;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        $campana = Campana::firstOrFail();
        $this->campanaId = $campana->id;
        $this->categoriaId = CategoriaProducto::firstOrFail()->id;
        $this->marcaId = Marca::firstOrFail()->id;

        $this->importacion = ImportacionCatalogo::create([
            'linea_id'                => $campana->linea_id,
            'campana_id'              => $campana->id,
            'archivo_url'             => 'catalogos-ia/2026/11/impuls.pdf',
            'tipo_archivo'            => 'pdf',
            'estado'                  => 'requiere_revision',
            'paginas'                 => 16,
            'iniciada_por_usuario_id' => $this->adminGeneral()->id,
        ]);
    }

    private function adminGeneral(): Usuario
    {
        return Usuario::where('email', \Database\Seeders\AdminGeneralSeeder::EMAIL)->firstOrFail();
    }

    private function unRevisado(): ProductoImportadoStaging
    {
        return ProductoImportadoStaging::create([
            'importacion_id'  => $this->importacion->id,
            'datos_extraidos' => [
                'pagina'          => 1,
                'marca'           => null,
                'modelo'          => 'IMPULS-1',
                'nombre'          => null,
                'color'           => 'NEGRO',
                'color_base_id'   => Color::whereRaw('LOWER(nombre) = ?', ['negro'])->firstOrFail()->id,
                'codigo_catalogo' => 'JR4616',
                'precio_publico'  => 1699.0,
                'talla_desde'     => '22',
                'talla_hasta'     => '24',
                'medios_numeros'  => false,
                'disponibilidad'  => null,
            ],
            'campos_dudosos' => [],
            'estado'         => 'corregido',
        ]);
    }

    private function correrElTrabajo(?string $ip): void
    {
        $this->importacion->update(['estado' => 'aprobando']);

        AprobarCatalogoImportado::dispatchSync(
            $this->importacion->id,
            $this->campanaId,
            $this->categoriaId,
            $this->marcaId,
            $this->adminGeneral()->id,
            $ip,
        );
    }

    /** @return \Illuminate\Support\Collection<int, Auditoria> */
    private function loQueDejoElTrabajo()
    {
        return Auditoria::whereIn('accion', [
            'importacion_catalogo.aprobada',
            'catalogo.producto.creado',
            'catalogo.publicacion.creada',
        ])->get();
    }

    // ------------------------------------------------------------------

    public function test_la_auditoria_del_worker_guarda_la_ip_de_quien_lo_pidio(): void
    {
        $this->unRevisado();

        $this->correrElTrabajo(self::IP_DE_QUIEN_PIDIO);

        $filas = $this->loQueDejoElTrabajo();

        $this->assertGreaterThan(0, $filas->count(), 'El trabajo tiene que dejar auditoría.');

        foreach ($filas as $fila) {
            $this->assertSame(
                self::IP_DE_QUIEN_PIDIO,
                $fila->ip_origen,
                'Cada fila debe decir desde dónde se pidió, no desde dónde corrió el worker.'
            );
        }
    }

    /**
     * Encolado sin petición (una orden de consola, por ejemplo): no hay IP de
     * origen y vacío es la respuesta honesta. `127.0.0.1` sería peor, porque
     * parece un dato real.
     */
    public function test_sin_ip_de_origen_se_guarda_vacio_y_no_la_del_servidor(): void
    {
        $this->unRevisado();

        $this->correrElTrabajo(null);

        $filas = $this->loQueDejoElTrabajo();

        $this->assertGreaterThan(0, $filas->count());

        foreach ($filas as $fila) {
            $this->assertNull($fila->ip_origen);
            $this->assertNotSame('127.0.0.1', $fila->ip_origen);
        }
    }

    public function test_la_pantalla_manda_la_ip_al_encolar(): void
    {
        Queue::fake();
        $this->unRevisado();
        $this->actingAs($this->adminGeneral());

        Livewire::test('admin.revisar-catalogo', ['importacion' => $this->importacion->id])
            ->set('campanaId', $this->campanaId)
            ->set('categoriaPorOmision', $this->categoriaId)
            ->set('marcaPorOmision', $this->marcaId)
            ->call('aprobar')
            ->assertHasNoErrors();

        Queue::assertPushed(
            AprobarCatalogoImportado::class,
            fn (AprobarCatalogoImportado $job): bool => $job->ipOrigen !== null
        );
    }

    // ------------------------------------------------------------------
    // Que el ámbito no se quede pegado
    // ------------------------------------------------------------------

    /**
     * Si el valor se quedara puesto, el siguiente registro de CUALQUIER
     * petición heredaría la IP de un trabajo que ya terminó. Por eso el
     * ámbito restaura en un finally, y por eso se prueba con una excepción.
     */
    public function test_el_ambito_se_restaura_aunque_el_trabajo_truene(): void
    {
        try {
            RegistrarAuditoriaAction::conIpDeOrigen('203.0.113.9', function (): void {
                throw new RuntimeException('se cayó a la mitad');
            });
        } catch (RuntimeException) {
            // Esperado.
        }

        $this->actingAs($this->adminGeneral());

        $registro = app(RegistrarAuditoriaAction::class)->ejecutar(
            accion: 'prueba.despues_del_ambito',
            entidadTipo: 'prueba',
            entidadId: 1,
        );

        $this->assertNotSame('203.0.113.9', $registro->ip_origen, 'La IP del trabajo no se puede quedar pegada.');
    }

    public function test_los_ambitos_anidados_devuelven_el_valor_de_afuera(): void
    {
        $this->actingAs($this->adminGeneral());

        $dentro = null;

        RegistrarAuditoriaAction::conIpDeOrigen('198.51.100.1', function () use (&$dentro) {
            RegistrarAuditoriaAction::conIpDeOrigen('203.0.113.9', fn () => null);

            $dentro = app(RegistrarAuditoriaAction::class)->ejecutar(
                accion: 'prueba.anidada',
                entidadTipo: 'prueba',
                entidadId: 2,
            )->ip_origen;
        });

        $this->assertSame('198.51.100.1', $dentro);
    }

    /**
     * Lo de siempre no cambia: una acción normal, dentro de una petición,
     * sigue guardando la IP de esa petición.
     */
    public function test_fuera_de_la_cola_la_auditoria_sigue_igual(): void
    {
        $this->actingAs($this->adminGeneral());

        $registro = app(RegistrarAuditoriaAction::class)->ejecutar(
            accion: 'prueba.peticion_normal',
            entidadTipo: 'prueba',
            entidadId: 3,
        );

        $this->assertSame('127.0.0.1', $registro->ip_origen);
    }
}
