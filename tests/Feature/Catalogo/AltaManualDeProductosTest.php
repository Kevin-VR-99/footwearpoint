<?php

namespace Tests\Feature\Catalogo;

use App\Models\Campana;
use App\Models\CategoriaProducto;
use App\Models\Color;
use App\Models\DisponibilidadVarianteCampana;
use App\Models\Distribuidora;
use App\Models\DistribuidoraLinea;
use App\Models\Marca;
use App\Models\ProductoCampana;
use App\Models\Talla;
use App\Models\Temporada;
use App\Models\Usuario;
use App\Models\Variante;
use App\Services\Catalogo\CatalogoVisible;
use App\Services\Tienda\TiendaPublica;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreaAdminGeneral;
use Tests\TestCase;

/**
 * TG-299 A14 — El alta manual de productos del catálogo maestro.
 *
 * El defecto: el selector de temporada ofrecía TODAS las campañas, también
 * las de temporadas ya terminadas. Elegir una guardaba el producto y decía
 * "publicado", pero CatalogoVisible solo enseña las de la temporada vigente;
 * el producto nunca aparecía en el marketplace de la distribuidora y nada
 * avisaba por qué.
 *
 * La regla correcta ya existía (CampanaDeTemporada: la temporada actual y la
 * siguiente) y la aplican la importación y la revisión de catálogos. El alta
 * manual era la única puerta de entrada que se la saltaba.
 */
class AltaManualDeProductosTest extends TestCase
{
    use CreaAdminGeneral;
    use RefreshDatabase;

    protected bool $seed = true;

    private function comoAdminGeneral(): Usuario
    {
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        $admin = $this->adminGeneral();
        $this->actingAs($admin);
        Tenant::olvidarCache();

        return $admin;
    }

    private function campanaVigente(): Campana
    {
        return Campana::vigentes()->firstOrFail();
    }

    /** Una campaña de una temporada que ya pasó, igual que las que quedan de años anteriores. */
    private function campanaTerminada(): Campana
    {
        $pasada = Temporada::create([
            'nombre' => 'Temporada terminada A14',
            'fecha_inicio' => '2000-01-01',
            'fecha_fin' => '2000-06-30',
        ]);

        return Campana::create([
            'linea_id' => $this->campanaVigente()->linea_id,
            'temporada_id' => $pasada->id,
            'nombre' => 'Temporada terminada A14',
            'fecha_inicio' => $pasada->fecha_inicio,
            'fecha_fin' => $pasada->fecha_fin,
            'estado' => 'finalizada',
        ]);
    }

    private function campanaDeLaProxima(): Campana
    {
        $siguiente = Temporada::siguiente();

        return Campana::create([
            'linea_id' => $this->campanaVigente()->linea_id,
            'temporada_id' => $siguiente->id,
            'nombre' => 'Temporada próxima A14',
            'fecha_inicio' => $siguiente->fecha_inicio,
            'fecha_fin' => $siguiente->fecha_fin,
            'estado' => 'borrador',
        ]);
    }

    /** @return array<string, mixed> el formulario lleno, listo para guardar */
    private function formulario(Campana $campana, string $codigo): array
    {
        return [
            'producto_marca_id' => Marca::query()->value('id'),
            'producto_categoria_id' => CategoriaProducto::query()->where('activa', true)->value('id'),
            'producto_modelo' => 'MOD-'.$codigo,
            'producto_nombre' => 'Producto '.$codigo,
            'producto_campana_id' => $campana->id,
            'producto_codigo' => $codigo,
            'producto_precio' => 799.50,
        ];
    }

    public function test_el_selector_no_ofrece_temporadas_terminadas(): void
    {
        $this->comoAdminGeneral();
        $terminada = $this->campanaTerminada();

        // Si se puede elegir, alguien la va a elegir: dos campañas de la misma
        // línea se ven casi iguales en la lista.
        Livewire::test('admin.catalogo-maestro')
            ->set('pestana', 'productos')
            ->call('abrirCrear', 'producto')
            ->assertDontSee($terminada->nombre);
    }

    public function test_no_deja_dar_de_alta_en_una_temporada_terminada(): void
    {
        $this->comoAdminGeneral();
        $terminada = $this->campanaTerminada();

        // El menú es del navegador y se puede manipular: la validación es la
        // que de verdad cierra la puerta.
        Livewire::test('admin.catalogo-maestro')
            ->set('pestana', 'productos')
            ->call('abrirCrear', 'producto')
            ->set($this->formulario($terminada, 'A14-MUERTA'))
            ->call('guardarProducto')
            ->assertHasErrors('producto_campana_id');

        $this->assertFalse(
            ProductoCampana::where('codigo_catalogo', 'A14-MUERTA')->exists(),
            'Se guardó un producto en una temporada terminada: quedaría invisible en el marketplace.'
        );
    }

    /** TG-334 (K36): el alta a mano deja rastro en la auditoría del admin general. */
    public function test_el_alta_manual_queda_en_la_auditoria_del_admin_general(): void
    {
        $admin = $this->comoAdminGeneral();

        Livewire::test('admin.catalogo-maestro')
            ->set('pestana', 'productos')
            ->call('abrirCrear', 'producto')
            ->set($this->formulario($this->campanaVigente(), 'A14-AUDITADO'))
            ->call('guardarProducto')
            ->assertHasNoErrors();

        $publicacion = ProductoCampana::where('codigo_catalogo', 'A14-AUDITADO')->firstOrFail();
        $registros = \App\Models\Auditoria::withoutGlobalScopes()
            ->whereIn('accion', ['catalogo.producto.creado', 'catalogo.publicacion.creada'])
            ->orderBy('id')->get();

        $this->assertSame(['catalogo.producto.creado', 'catalogo.publicacion.creada'], $registros->pluck('accion')->all());
        foreach ($registros as $registro) {
            $this->assertSame($admin->id, $registro->usuario_id);
            $this->assertNull($registro->distribuidora_id);
            $this->assertNull($registro->datos_previos);
        }
        $this->assertSame((int) $publicacion->producto_id, $registros[0]->entidad_id);
        $this->assertSame('A14-AUDITADO', $registros[1]->datos_nuevos['codigo_catalogo']);
    }

    public function test_el_producto_de_la_temporada_vigente_si_llega_al_catalogo_de_la_distribuidora(): void
    {
        $this->comoAdminGeneral();
        $vigente = $this->campanaVigente();

        Livewire::test('admin.catalogo-maestro')
            ->set('pestana', 'productos')
            ->call('abrirCrear', 'producto')
            ->set($this->formulario($vigente, 'A14-VIVA'))
            ->call('guardarProducto')
            ->assertHasNoErrors();

        $publicacion = ProductoCampana::where('codigo_catalogo', 'A14-VIVA')->firstOrFail();

        $distribuidoraId = Distribuidora::query()->value('id');
        DistribuidoraLinea::withoutGlobalScopes()->firstOrCreate(
            ['distribuidora_id' => $distribuidoraId, 'linea_id' => $vigente->linea_id],
            ['es_extra' => false, 'activa' => true, 'fecha_activacion' => now()]
        );

        $visible = Tenant::forzar(
            $distribuidoraId,
            fn () => app(CatalogoVisible::class)->puedeVender($publicacion->id)
        );

        $this->assertTrue($visible, 'El producto dado de alta a mano no llegó al catálogo de la distribuidora.');
    }

    public function test_avisa_cuando_el_producto_queda_para_la_proxima_temporada(): void
    {
        $this->comoAdminGeneral();
        $proxima = $this->campanaDeLaProxima();

        // Cargar la próxima por adelantado es correcto, pero el producto no se
        // ve todavía. Decirle "publicado" fue justo lo que confundió antes.
        Livewire::test('admin.catalogo-maestro')
            ->set('pestana', 'productos')
            ->call('abrirCrear', 'producto')
            ->set($this->formulario($proxima, 'A14-PROXIMA'))
            ->call('guardarProducto')
            ->assertHasNoErrors()
            ->assertDispatched(
                'guardado',
                mensaje: 'Producto creado en la próxima temporada: se publica cuando empiece, y en la tienda pública necesita tallas y colores.'
            );
    }

    /**
     * El alta a mano no crea tallas ni colores, y TiendaPublica solo enseña
     * lo que se puede pedir: el producto entra al catálogo del panel y de la
     * app, pero no a la tienda pública. Antes decía "publicado" y el admin
     * se iba a buscarlo al marketplace sin encontrarlo nunca.
     */
    public function test_avisa_que_sin_tallas_ni_colores_no_sale_en_la_tienda_publica(): void
    {
        $this->comoAdminGeneral();
        $vigente = $this->campanaVigente();

        Livewire::test('admin.catalogo-maestro')
            ->set('pestana', 'productos')
            ->call('abrirCrear', 'producto')
            ->set($this->formulario($vigente, 'A14-SIN-TALLAS'))
            ->call('guardarProducto')
            ->assertHasNoErrors()
            ->assertDispatched(
                'guardado',
                mensaje: 'Producto creado. En la tienda pública aparecerá cuando tenga tallas y colores.'
            );

        // La razón del aviso, comprobada y no supuesta.
        $publicacion = ProductoCampana::where('codigo_catalogo', 'A14-SIN-TALLAS')->firstOrFail();

        $this->assertSame(
            0,
            $publicacion->disponibilidadPorVariante()->count(),
            'Si el alta ya crea variantes, el aviso sobra y hay que quitarlo.'
        );
    }

    // ------------------------------------------------------------------
    // Tallas y colores: lo que le faltaba al alta manual para servir
    // ------------------------------------------------------------------

    /** Crea el producto y le abre su modal de tallas. */
    private function conProductoYModalDeTallas(string $codigo): array
    {
        $vigente = $this->campanaVigente();

        $componente = Livewire::test('admin.catalogo-maestro')
            ->set('pestana', 'productos')
            ->call('abrirCrear', 'producto')
            ->set($this->formulario($vigente, $codigo))
            ->call('guardarProducto')
            ->assertHasNoErrors();

        $publicacion = ProductoCampana::where('codigo_catalogo', $codigo)->firstOrFail();

        $componente->call('abrirTallas', $publicacion->id)->assertSet('tallasDe', $publicacion->id);

        return [$componente, $publicacion, $vigente];
    }

    /** Que la distribuidora venda esa linea, si no el producto no le toca. */
    private function queLaVenda(Campana $campana): int
    {
        $distribuidoraId = (int) Distribuidora::query()->value('id');

        DistribuidoraLinea::withoutGlobalScopes()->firstOrCreate(
            ['distribuidora_id' => $distribuidoraId, 'linea_id' => $campana->linea_id],
            ['es_extra' => false, 'activa' => true, 'fecha_activacion' => now()]
        );

        return $distribuidoraId;
    }

    /** TiendaPublica::producto responde 404 si la distribuidora no lo vende. */
    private function seVeEnLaTienda(int $distribuidoraId, int $productoCampanaId): bool
    {
        try {
            app(TiendaPublica::class)->producto(Distribuidora::find($distribuidoraId), $productoCampanaId);

            return true;
        } catch (NotFoundHttpException) {
            return false;
        }
    }

    /**
     * La prueba que cierra A14: el recorrido completo, desde el alta a mano
     * hasta que el producto se puede pedir en la tienda publica. Era imposible
     * antes, porque no habia manera de darle tallas desde el panel.
     */
    public function test_con_tallas_el_producto_creado_a_mano_si_llega_a_la_tienda_publica(): void
    {
        $this->comoAdminGeneral();
        [$componente, $publicacion, $vigente] = $this->conProductoYModalDeTallas('A14-CON-TALLAS');

        $distribuidoraId = $this->queLaVenda($vigente);

        // Recien creado todavia no se puede pedir: ese era el bug.
        $this->assertFalse(
            $this->seVeEnLaTienda($distribuidoraId, $publicacion->id),
            'Sin tallas no deberia verse en la tienda.'
        );

        $componente
            ->set('tallaColorId', Color::query()->value('id'))
            ->set('tallasElegidas', Talla::query()->where('activa', true)->orderBy('orden')->limit(3)->pluck('id')->all())
            ->set('tallaEstado', 'disponible')
            ->call('agregarTallas')
            ->assertHasNoErrors();

        $this->assertTrue(
            $this->seVeEnLaTienda($distribuidoraId, $publicacion->id),
            'Con tallas que se pueden pedir, el producto tiene que verse en la tienda.'
        );
    }

    public function test_agregar_tallas_crea_una_variante_por_talla_y_su_disponibilidad(): void
    {
        $this->comoAdminGeneral();
        [$componente, $publicacion] = $this->conProductoYModalDeTallas('A14-VARIANTES');

        $componente
            ->set('tallaColorId', Color::query()->value('id'))
            ->set('tallasElegidas', Talla::query()->where('activa', true)->orderBy('orden')->limit(4)->pluck('id')->all())
            ->set('tallaColorComercial', 'MIEL')
            ->call('agregarTallas')
            ->assertHasNoErrors();

        $this->assertSame(4, $publicacion->disponibilidadPorVariante()->count());
        $this->assertSame(4, Variante::where('producto_id', $publicacion->producto_id)->count());

        // El nombre del catalogo se guarda tal cual, igual que en la importacion.
        $this->assertSame(
            'MIEL',
            Variante::where('producto_id', $publicacion->producto_id)->value('nombre_color_comercial')
        );
    }

    /**
     * La variante es del producto; la disponibilidad, de la temporada. Repetir
     * la misma talla y color no puede duplicar la variante: hay una
     * restriccion unica en la base y reventaria.
     */
    public function test_repetir_la_misma_talla_y_color_no_duplica_la_variante(): void
    {
        $this->comoAdminGeneral();
        [$componente, $publicacion] = $this->conProductoYModalDeTallas('A14-REPETIDA');

        $talla = Talla::query()->where('activa', true)->value('id');
        $color = Color::query()->value('id');

        foreach (['disponible', 'bajo_pedido'] as $estado) {
            $componente
                ->set('tallaColorId', $color)
                ->set('tallasElegidas', [$talla])
                ->set('tallaEstado', $estado)
                ->call('agregarTallas')
                ->assertHasNoErrors();
        }

        $this->assertSame(1, Variante::where('producto_id', $publicacion->producto_id)->count());
        $this->assertSame(1, $publicacion->disponibilidadPorVariante()->count());
        $this->assertSame('bajo_pedido', $publicacion->disponibilidadPorVariante()->value('estado'));
    }

    /**
     * Quitar una talla no puede borrar la variante: los pedidos guardan
     * variante_id y se les caeria el historial. Se deja de ofrecer y ya.
     */
    public function test_quitar_una_talla_no_borra_la_variante(): void
    {
        $this->comoAdminGeneral();
        [$componente, $publicacion] = $this->conProductoYModalDeTallas('A14-QUITAR');

        $componente
            ->set('tallaColorId', Color::query()->value('id'))
            ->set('tallasElegidas', [Talla::query()->where('activa', true)->value('id')])
            ->call('agregarTallas')
            ->assertHasNoErrors();

        $disponibilidad = $publicacion->disponibilidadPorVariante()->firstOrFail();

        $componente->call('quitarTalla', $disponibilidad->id);

        $this->assertTrue(
            Variante::whereKey($disponibilidad->variante_id)->exists(),
            'Se borro la variante: los pedidos que la referencian quedarian rotos.'
        );
        $this->assertSame('no_disponible', $disponibilidad->fresh()->estado);

        // Y se puede deshacer, si no seria una trampa sin salida.
        $componente->call('volverAOfrecerTalla', $disponibilidad->id);

        $this->assertSame('disponible', $disponibilidad->fresh()->estado);
    }

    public function test_no_deja_agregar_tallas_sin_elegir_color_ni_tallas(): void
    {
        $this->comoAdminGeneral();
        [$componente] = $this->conProductoYModalDeTallas('A14-VACIO');

        $componente
            ->set('tallaColorId', '')
            ->set('tallasElegidas', [])
            ->call('agregarTallas')
            ->assertHasErrors(['tallaColorId', 'tallasElegidas']);
    }

    /** El id viaja desde el navegador: no puede tocar la disponibilidad de otra publicacion. */
    public function test_no_se_puede_tocar_la_talla_de_otra_publicacion(): void
    {
        $this->comoAdminGeneral();
        [$componente, $publicacion] = $this->conProductoYModalDeTallas('A14-AJENA');

        $ajena = DisponibilidadVarianteCampana::query()
            ->where('producto_campana_id', '!=', $publicacion->id)
            ->firstOrFail();

        // findOrFail acotado a la publicacion abierta: Laravel lo vuelve un 404.
        try {
            $componente->call('quitarTalla', $ajena->id);
            $this->fail('Se dejo tocar la disponibilidad de otra publicacion.');
        } catch (ModelNotFoundException) {
            // Es justo lo que tiene que pasar.
        }

        $this->assertNotSame('no_disponible', $ajena->fresh()->estado);
    }
}
