<?php

namespace Tests\Feature\Catalogo;

use App\Models\Auditoria;
use App\Models\Linea;
use App\Models\Marca;
use App\Rules\SvgSeguro;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\CreaAdminGeneral;
use Tests\TestCase;

/**
 * Logos de líneas y marcas en el catálogo maestro: se suben, se reemplazan y
 * se quitan (borrando el archivo anterior), se auditan, y en la lista se ve
 * la miniatura o, sin logo, las iniciales.
 */
class LogotiposLineasMarcasTest extends TestCase
{
    use CreaAdminGeneral;
    use RefreshDatabase;

    protected bool $seed = true;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private const SVG_LIMPIO = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><defs><linearGradient id="g"><stop offset="0" stop-color="#7E22CE"/></linearGradient></defs><rect width="10" height="10" fill="url(#g)"/></svg>';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('s3');
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        $this->actingAs($this->adminGeneral());
        Tenant::olvidarCache();
    }

    private function png(string $nombre = 'logo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nombre, base64_decode(self::PNG));
    }

    private function svg(string $contenido, string $nombre = 'logo.svg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nombre, $contenido);
    }

    public function test_crear_una_linea_con_logo_lo_sube_a_lineas_logotipos(): void
    {
        Livewire::test('admin.catalogo-maestro')
            ->call('abrirCrear', 'linea')
            ->set('linea_nombre', 'Línea Con Logo')
            ->set('linea_logotipo', $this->png())
            ->call('guardarLinea')
            ->assertHasNoErrors()
            ->assertSet('linea_logotipo', null);

        $linea = Linea::where('nombre', 'Línea Con Logo')->firstOrFail();
        $this->assertNotNull($linea->logotipo_url);
        $this->assertCount(1, Storage::disk('s3')->files('lineas/logotipos'));
        $this->assertTrue(Auditoria::where('accion', 'catalogo.linea.creada')->where('entidad_id', $linea->id)->exists());
    }

    public function test_crear_una_marca_con_logo_svg_limpio(): void
    {
        Livewire::test('admin.catalogo-maestro')
            ->set('pestana', 'marcas')
            ->call('abrirCrear', 'marca')
            ->set('marca_nombre', 'Marca Con Logo')
            ->set('marca_logotipo', $this->svg(self::SVG_LIMPIO))
            ->call('guardarMarca')
            ->assertHasNoErrors();

        $this->assertNotNull(Marca::where('nombre', 'Marca Con Logo')->value('logotipo_url'));
        $this->assertCount(1, Storage::disk('s3')->files('marcas/logotipos'));
    }

    public function test_reemplazar_el_logo_de_una_linea_borra_el_anterior_y_se_audita(): void
    {
        $linea = Linea::create(['nombre' => 'Línea Reemplazo', 'activa' => true]);

        Livewire::test('admin.catalogo-maestro')
            ->call('editar', 'linea', $linea->id)
            ->set('editar_logotipo', $this->png('uno.png'))
            ->call('guardarEdicion')
            ->assertHasNoErrors();

        $primera = $linea->fresh()->logotipo_url;
        $this->assertCount(1, Storage::disk('s3')->files('lineas/logotipos'));

        Livewire::test('admin.catalogo-maestro')
            ->call('editar', 'linea', $linea->id)
            ->assertSet('editar_logotipo_actual', $primera)
            ->set('editar_logotipo', $this->png('dos.png'))
            ->call('guardarEdicion')
            ->assertHasNoErrors();

        $segunda = $linea->fresh()->logotipo_url;
        $this->assertNotEquals($primera, $segunda);
        $archivos = Storage::disk('s3')->files('lineas/logotipos');
        $this->assertCount(1, $archivos);
        $this->assertStringEndsWith($archivos[0], $segunda);

        $auditoria = Auditoria::where('accion', 'catalogo.linea.actualizada')->where('entidad_id', $linea->id)->latest('id')->firstOrFail();
        $this->assertEquals(['logotipo_url' => $primera], $auditoria->datos_previos);
        $this->assertEquals(['logotipo_url' => $segunda], $auditoria->datos_nuevos);
    }

    public function test_quitar_el_logo_de_una_marca_lo_borra_del_almacenamiento(): void
    {
        $marca = Marca::create(['nombre' => 'Marca Quitar', 'activa' => true]);

        Livewire::test('admin.catalogo-maestro')
            ->call('editar', 'marca', $marca->id)
            ->set('editar_logotipo', $this->png())
            ->call('guardarEdicion')
            ->assertHasNoErrors();

        $url = $marca->fresh()->logotipo_url;
        $this->assertCount(1, Storage::disk('s3')->files('marcas/logotipos'));

        Livewire::test('admin.catalogo-maestro')
            ->set('pestana', 'marcas')
            ->call('editar', 'marca', $marca->id)
            ->assertSee('Quitar logo')
            ->call('quitarLogotipo')
            ->assertSet('editar_quitar_logotipo', true)
            ->call('guardarEdicion')
            ->assertHasNoErrors();

        $this->assertNull($marca->fresh()->logotipo_url);
        $this->assertCount(0, Storage::disk('s3')->files('marcas/logotipos'));

        $auditoria = Auditoria::where('accion', 'catalogo.marca.actualizada')->where('entidad_id', $marca->id)->latest('id')->firstOrFail();
        $this->assertEquals(['logotipo_url' => $url], $auditoria->datos_previos);
        $this->assertEquals(['logotipo_url' => null], $auditoria->datos_nuevos);
    }

    public function test_una_url_externa_no_se_borra_al_quitar_el_logo(): void
    {
        Storage::disk('s3')->put('otra-carpeta/no-tocar.png', 'x');
        $marca = Marca::create([
            'nombre' => 'Marca Externa',
            'activa' => true,
            'logotipo_url' => Storage::disk('s3')->url('otra-carpeta/no-tocar.png'),
        ]);

        Livewire::test('admin.catalogo-maestro')
            ->call('editar', 'marca', $marca->id)
            ->call('quitarLogotipo')
            ->call('guardarEdicion')
            ->assertHasNoErrors();

        $this->assertNull($marca->fresh()->logotipo_url);
        Storage::disk('s3')->assertExists('otra-carpeta/no-tocar.png');
    }

    public function test_valida_tipo_y_tamano_del_logo(): void
    {
        Livewire::test('admin.catalogo-maestro')
            ->call('abrirCrear', 'linea')
            ->set('linea_nombre', 'Línea Mal Logo')
            ->set('linea_logotipo', UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'))
            ->call('guardarLinea')
            ->assertHasErrors('linea_logotipo')
            ->assertSee('El logo debe ser JPG, PNG, WebP o SVG.');

        Livewire::test('admin.catalogo-maestro')
            ->call('abrirCrear', 'marca')
            ->set('marca_nombre', 'Marca Logo Pesado')
            ->set('marca_logotipo', UploadedFile::fake()->image('logo.png')->size(3000))
            ->call('guardarMarca')
            ->assertHasErrors('marca_logotipo');

        $this->assertFalse(Linea::where('nombre', 'Línea Mal Logo')->exists());
        $this->assertFalse(Marca::where('nombre', 'Marca Logo Pesado')->exists());
        $this->assertCount(0, Storage::disk('s3')->allFiles());
    }

    public function test_se_acepta_webp(): void
    {
        $linea = Linea::create(['nombre' => 'Línea WebP', 'activa' => true]);
        $webp = base64_decode('UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAwA0JaQAA3AA/vuUAAA=');

        Livewire::test('admin.catalogo-maestro')
            ->call('editar', 'linea', $linea->id)
            ->set('editar_logotipo', UploadedFile::fake()->createWithContent('logo.webp', $webp))
            ->call('guardarEdicion')
            ->assertHasNoErrors();

        $this->assertNotNull($linea->fresh()->logotipo_url);
    }

    public function test_un_svg_con_codigo_se_rechaza_y_no_se_sube(): void
    {
        $marca = Marca::create(['nombre' => 'Marca SVG', 'activa' => true]);

        Livewire::test('admin.catalogo-maestro')
            ->call('editar', 'marca', $marca->id)
            ->set('editar_logotipo', $this->svg('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'))
            ->call('guardarEdicion')
            ->assertHasErrors('editar_logotipo')
            ->assertSee('El SVG trae código o referencias externas');

        $this->assertNull($marca->fresh()->logotipo_url);
        $this->assertCount(0, Storage::disk('s3')->allFiles());
    }

    public function test_el_api_de_lineas_acepta_y_quita_el_logo(): void
    {
        $linea = Linea::create(['nombre' => 'Línea API', 'activa' => true]);

        $this->post("/api/lineas/{$linea->id}", ['_method' => 'PATCH', 'logotipo' => $this->png()], ['Accept' => 'application/json'])
            ->assertOk();
        $this->assertNotNull($linea->fresh()->logotipo_url);
        $this->assertCount(1, Storage::disk('s3')->files('lineas/logotipos'));

        $this->patchJson("/api/lineas/{$linea->id}", ['quitar_logotipo' => true])->assertOk();
        $this->assertNull($linea->fresh()->logotipo_url);
        $this->assertCount(0, Storage::disk('s3')->files('lineas/logotipos'));

        $this->post("/api/lineas/{$linea->id}", ['_method' => 'PATCH', 'logotipo' => $this->svg('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>')], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('logotipo');
    }

    public function test_svgs_peligrosos_y_limpios(): void
    {
        $peligrosos = [
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect/></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg"><rect ONCLICK="x()"/></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><use xlink:href="https://malo.test/a.svg#x"/></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg"><a href="javascript:alert(1)"><rect/></a></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><div xmlns="http://www.w3.org/1999/xhtml">x</div></foreignObject></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg"><style>@import url(https://malo.test/x.css);</style></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg"><rect style="fill:url(https://malo.test/x)"/></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg"><image href="https://malo.test/x.png"/></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg"><set attributeName="href" to="javascript:alert(1)"/></svg>',
            '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg">&x;</svg>',
            '<?xml-stylesheet href="https://malo.test/x.css"?><svg xmlns="http://www.w3.org/2000/svg"/>',
            '<html><script>alert(1)</script></html>',
            'no es xml',
        ];

        foreach ($peligrosos as $svg) {
            $this->assertFalse(SvgSeguro::esSeguro($svg), $svg);
        }

        $this->assertTrue(SvgSeguro::esSeguro(self::SVG_LIMPIO));
        $this->assertTrue(SvgSeguro::esSeguro('<svg xmlns="http://www.w3.org/2000/svg"><defs><path id="p" d="M0 0h1"/></defs><use href="#p"/></svg>'));
    }

    public function test_la_lista_muestra_la_miniatura_o_las_iniciales(): void
    {
        Linea::create(['nombre' => 'Zeta Con Logo', 'activa' => true, 'logotipo_url' => 'https://cdn.ejemplo.test/lineas/logotipos/zeta.png']);
        Linea::create(['nombre' => 'Zulu Sin Logo', 'activa' => true]);
        Marca::create(['nombre' => 'Marca Ilustrada Uno', 'activa' => true, 'logotipo_url' => 'https://cdn.ejemplo.test/marcas/logotipos/mi.png']);
        Marca::create(['nombre' => 'Otra Sinlogo', 'activa' => true]);

        Livewire::test('admin.catalogo-maestro')
            ->assertSeeHtml('src="https://cdn.ejemplo.test/lineas/logotipos/zeta.png"')
            ->assertSeeHtml('alt="Logo de Zeta Con Logo"')
            ->assertSeeHtml('fp-tarjeta-interactiva')
            ->assertSeeHtml('group-hover:-translate-y-0.5 group-hover:scale-110')
            ->assertDontSeeHtml('hover:shadow-fp-sidebar/10')
            ->assertSeeHtml('object-contain')
            ->assertSeeHtml('loading="lazy"')
            ->assertSeeHtml('data-logotipo-iniciales')
            ->assertSee('ZS')
            ->set('pestana', 'marcas')
            ->assertSeeHtml('src="https://cdn.ejemplo.test/marcas/logotipos/mi.png"')
            ->assertSee('Otra Sinlogo, sin logo')
            ->assertSee('OS');
    }

    public function test_el_modal_muestra_la_vista_previa_del_logo_elegido(): void
    {
        Livewire::test('admin.catalogo-maestro')
            ->call('abrirCrear', 'marca')
            ->assertSeeHtml('data-campo-logotipo')
            ->assertSeeHtml('accept="image/png,image/jpeg,image/webp,image/svg+xml"')
            ->set('marca_logotipo', $this->png())
            ->assertSeeHtml('alt="Logo de Vista previa"')
            ->assertSee('Quitar logo');
    }
}
