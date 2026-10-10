<?php

namespace Tests\Feature\Catalogo;

use App\Models\Auditoria;
use App\Models\ImportacionCatalogo;
use App\Models\ProductoImportadoStaging;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TG-239 (A9 / E5-03) — Revisar y corregir lo que propuso la IA.
 *
 * Los datos de estas pruebas imitan lo que salió del catálogo Confort real:
 * productos sin marca (el catálogo no la trae impresa), precios dudosos y
 * alguna fila que no era calzado.
 */
class RevisarProductosImportadosTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private ImportacionCatalogo $importacion;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        $this->importacion = ImportacionCatalogo::create([
            'linea_id' => 3,
            'archivo_url' => 'catalogos-ia/2026/10/confort.pdf',
            'tipo_archivo' => 'pdf',
            'estado' => 'requiere_revision',
            'paginas' => 76,
            'iniciada_por_usuario_id' => $this->adminGeneral()->id,
        ]);
    }

    private function adminGeneral(): Usuario
    {
        return Usuario::where('email', 'admin.general@footwearpoint.test')->firstOrFail();
    }

    /** @param array<string, mixed> $datos */
    private function producto(array $datos, array $dudosos = [], string $estado = 'pendiente'): ProductoImportadoStaging
    {
        return ProductoImportadoStaging::create([
            'importacion_id'  => $this->importacion->id,
            'datos_extraidos' => $datos + [
                'pagina' => 3, 'marca' => null, 'modelo' => null, 'nombre' => null,
                'color' => 'MIEL', 'codigo_catalogo' => '033-39', 'precio_publico' => 699.0,
                'talla_desde' => '2', 'talla_hasta' => '6', 'medios_numeros' => false,
                'disponibilidad' => null,
            ],
            'campos_dudosos' => $dudosos,
            'estado' => $estado,
        ]);
    }

    private function pantalla()
    {
        $this->actingAs($this->adminGeneral());

        return Livewire::test('admin.revisar-catalogo', ['importacion' => $this->importacion->id]);
    }

    // ------------------------------------------------------------------
    // Quién puede entrar
    // ------------------------------------------------------------------

    public function test_solo_el_admin_general_puede_revisar(): void
    {
        $this->producto([]);

        $this->actingAs(Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail())
            ->get(route('admin.catalogos.revisar', $this->importacion->id))
            ->assertForbidden();
    }

    public function test_sin_iniciar_sesion_manda_al_login(): void
    {
        $this->get(route('admin.catalogos.revisar', $this->importacion->id))
            ->assertRedirect(route('login'));
    }

    public function test_una_importacion_que_no_existe_responde_404(): void
    {
        $this->actingAs($this->adminGeneral())
            ->get(route('admin.catalogos.revisar', 99999))
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Lo que se ve
    // ------------------------------------------------------------------

    public function test_por_omision_muestra_solo_lo_que_falta_por_revisar(): void
    {
        // Con 154 productos, abrir en una lista plana no sirve de nada.
        $this->producto(['codigo_catalogo' => 'POR-REVISAR']);
        $this->producto(['codigo_catalogo' => 'YA-CORREGIDO'], [], 'corregido');
        $this->producto(['codigo_catalogo' => 'DESCARTADO'], [], 'descartado');

        $this->pantalla()
            ->assertSee('POR-REVISAR')
            ->assertDontSee('YA-CORREGIDO')
            ->assertDontSee('DESCARTADO');
    }

    public function test_el_resumen_cuenta_cada_estado(): void
    {
        $this->producto([], ['marca']);
        $this->producto([], ['marca', 'precio_publico']);
        $this->producto([], [], 'corregido');
        $this->producto([], [], 'descartado');

        $resumen = $this->pantalla()->get('resumen');

        $this->assertSame(4, $resumen['total']);
        $this->assertSame(2, $resumen['por_revisar']);
        $this->assertSame(1, $resumen['corregidos']);
        $this->assertSame(1, $resumen['descartados']);
        $this->assertSame(2, $resumen['dudosos']);
    }

    public function test_se_puede_ver_todo_y_tambien_lo_descartado(): void
    {
        $this->producto(['codigo_catalogo' => 'VIVO']);
        $this->producto(['codigo_catalogo' => 'FUERA'], [], 'descartado');

        $pantalla = $this->pantalla();

        $pantalla->call('cambiarFiltro', 'todos')
            ->assertSee('VIVO')
            ->assertDontSee('FUERA');

        $pantalla->call('cambiarFiltro', 'descartados')
            ->assertSee('FUERA')
            ->assertDontSee('VIVO');
    }

    public function test_se_ve_que_campos_marco_la_ia_como_dudosos(): void
    {
        $this->producto([], ['marca', 'precio_publico']);

        $this->pantalla()
            ->assertSee('Marca')
            ->assertSee('Precio');
    }

    // ------------------------------------------------------------------
    // Corregir
    // ------------------------------------------------------------------

    public function test_se_puede_corregir_cualquier_campo(): void
    {
        $fila = $this->producto([], ['marca']);

        $this->pantalla()
            ->call('editar', $fila->id)
            ->set('campos.marca', 'Confort Dama')
            ->set('campos.color', 'MIEL OSCURO')
            ->set('campos.precio_publico', '749.50')
            ->call('guardar')
            ->assertHasNoErrors();

        $fila->refresh();

        $this->assertSame('Confort Dama', $fila->datos_extraidos['marca']);
        $this->assertSame('MIEL OSCURO', $fila->datos_extraidos['color']);
        $this->assertSame(749.5, $fila->datos_extraidos['precio_publico']);
        $this->assertSame('corregido', $fila->estado);
    }

    public function test_el_campo_que_toco_una_persona_deja_de_ser_dudoso(): void
    {
        // Es el punto de todo: la IA dudaba, una persona ya respondió.
        $fila = $this->producto([], ['marca', 'precio_publico']);

        $this->pantalla()
            ->call('editar', $fila->id)
            ->set('campos.marca', 'Confort Dama')
            ->call('guardar');

        // La marca ya la revisó alguien; el precio sigue sin mirarse.
        $this->assertSame(['precio_publico'], $fila->fresh()->campos_dudosos);
    }

    public function test_el_precio_tiene_que_ser_un_numero(): void
    {
        $fila = $this->producto([]);

        $this->pantalla()
            ->call('editar', $fila->id)
            ->set('campos.precio_publico', 'seiscientos')
            ->call('guardar')
            ->assertHasErrors(['campos.precio_publico']);

        $this->assertSame('pendiente', $fila->fresh()->estado);
    }

    public function test_la_pagina_es_obligatoria(): void
    {
        $fila = $this->producto([]);

        $this->pantalla()
            ->call('editar', $fila->id)
            ->set('campos.pagina', '')
            ->call('guardar')
            ->assertHasErrors(['campos.pagina']);
    }

    public function test_dejar_un_campo_vacio_lo_guarda_como_vacio_y_no_como_texto(): void
    {
        $fila = $this->producto(['disponibilidad' => 'hasta agotar existencias']);

        $this->pantalla()
            ->call('editar', $fila->id)
            ->set('campos.disponibilidad', '   ')
            ->call('guardar');

        $this->assertNull($fila->fresh()->datos_extraidos['disponibilidad']);
    }

    public function test_queda_anotado_quien_reviso_el_catalogo(): void
    {
        $fila = $this->producto([]);

        $this->assertNull($this->importacion->revisada_por_usuario_id);

        $this->pantalla()
            ->call('editar', $fila->id)
            ->set('campos.color', 'NEGRO')
            ->call('guardar');

        $this->assertSame($this->adminGeneral()->id, (int) $this->importacion->fresh()->revisada_por_usuario_id);
    }

    public function test_no_se_puede_corregir_un_producto_de_otra_importacion(): void
    {
        $otra = ImportacionCatalogo::create([
            'linea_id' => 1,
            'archivo_url' => 'catalogos-ia/2026/10/otro.pdf',
            'tipo_archivo' => 'pdf',
            'estado' => 'requiere_revision',
            'iniciada_por_usuario_id' => $this->adminGeneral()->id,
        ]);

        $ajeno = ProductoImportadoStaging::create([
            'importacion_id' => $otra->id,
            'datos_extraidos' => ['pagina' => 1, 'codigo_catalogo' => 'AJENO'],
            'estado' => 'pendiente',
        ]);

        $this->actingAs($this->adminGeneral());

        try {
            Livewire::test('admin.revisar-catalogo', ['importacion' => $this->importacion->id])
                ->call('editar', $ajeno->id);

            $this->fail('Dejó tocar un producto de otra importación.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->assertTrue(true);
        }
    }

    // ------------------------------------------------------------------
    // Descartar
    // ------------------------------------------------------------------

    public function test_se_puede_descartar_lo_que_no_era_calzado_y_recuperarlo(): void
    {
        $fila = $this->producto(['codigo_catalogo' => 'FOTO-DE-AMBIENTE']);

        $pantalla = $this->pantalla();

        $pantalla->call('descartar', $fila->id);
        $this->assertSame('descartado', $fila->fresh()->estado);

        // No se borra: se puede recuperar si alguien se equivocó.
        $pantalla->call('recuperar', $fila->id);
        $this->assertSame('pendiente', $fila->fresh()->estado);
    }

    // ------------------------------------------------------------------
    // Rellenar en lote (el caso de las 116 marcas)
    // ------------------------------------------------------------------

    public function test_completa_la_marca_en_todos_los_que_la_tienen_vacia(): void
    {
        $sinMarca1 = $this->producto(['marca' => null], ['marca']);
        $sinMarca2 = $this->producto(['marca' => null], ['marca']);
        $conMarca  = $this->producto(['marca' => 'Cklass']);

        $this->pantalla()
            ->set('campoEnLote', 'marca')
            ->set('valorEnLote', 'Confort Dama')
            ->call('completarEnLote')
            ->assertHasNoErrors()
            ->assertSet('esError', false);

        $this->assertSame('Confort Dama', $sinMarca1->fresh()->datos_extraidos['marca']);
        $this->assertSame('Confort Dama', $sinMarca2->fresh()->datos_extraidos['marca']);
        // Lo que la IA sí leyó no se pisa.
        $this->assertSame('Cklass', $conMarca->fresh()->datos_extraidos['marca']);
    }

    public function test_al_completar_en_lote_ese_campo_deja_de_ser_dudoso(): void
    {
        $fila = $this->producto(['marca' => null], ['marca', 'color']);

        $this->pantalla()
            ->set('campoEnLote', 'marca')
            ->set('valorEnLote', 'Confort Dama')
            ->call('completarEnLote');

        $fila->refresh();
        $this->assertSame(['color'], $fila->campos_dudosos);
        $this->assertSame('corregido', $fila->estado);
    }

    public function test_el_relleno_en_lote_no_toca_lo_descartado(): void
    {
        $descartado = $this->producto(['marca' => null], ['marca'], 'descartado');

        $this->pantalla()
            ->set('campoEnLote', 'marca')
            ->set('valorEnLote', 'Confort Dama')
            ->call('completarEnLote');

        $this->assertNull($descartado->fresh()->datos_extraidos['marca']);
    }

    public function test_el_relleno_en_lote_pide_un_valor(): void
    {
        $this->producto(['marca' => null], ['marca']);

        $this->pantalla()
            ->set('valorEnLote', '')
            ->call('completarEnLote')
            ->assertHasErrors(['valorEnLote']);
    }

    public function test_el_relleno_en_lote_queda_en_la_bitacora(): void
    {
        $this->producto(['marca' => null], ['marca']);
        $this->producto(['marca' => null], ['marca']);

        $this->pantalla()
            ->set('campoEnLote', 'marca')
            ->set('valorEnLote', 'Confort Dama')
            ->call('completarEnLote');

        $registro = Auditoria::withoutGlobalScopes()
            ->where('accion', 'importacion_catalogo.relleno_en_lote')
            ->latest('id')
            ->firstOrFail();

        $this->assertEquals('marca', $registro->datos_nuevos['campo']);
        $this->assertEquals(2, $registro->datos_nuevos['productos']);
    }

    public function test_no_se_puede_rellenar_en_lote_un_campo_que_no_se_permite(): void
    {
        // El precio NO: ponerle el mismo a todo el catálogo sería un desastre.
        $this->producto([]);

        $this->expectException(\InvalidArgumentException::class);

        app(\App\Services\Ia\CompletarCampoEnLoteAction::class)
            ->ejecutar($this->importacion, 'precio_publico', '699');
    }
}
