<?php

namespace Tests\Feature\FuncionesCompletas;

use App\Models\Auditoria;
use App\Models\ClienteDirecto;
use App\Services\Auth\PermisoModuloService;
use App\Services\Cliente\HistorialClienteAction;
use App\Services\Cliente\ImportarClientesAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\AyudasFuncionesCompletas;
use Tests\TestCase;

/** Historial 360 del cliente e importación de clientes con vista previa. */
class ClientesHistorialImportarTest extends TestCase
{
    use AyudasFuncionesCompletas;
    use RefreshDatabase;

    protected bool $seed = true;

    private function csv(string $contenido): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($ruta, $contenido);

        return $ruta;
    }

    public function test_el_historial_junta_pedidos_pagos_vales_y_saldo_y_respeta_el_tenant(): void
    {
        $this->comoPanel(self::ADMIN);
        $jose = ClienteDirecto::withoutGlobalScopes()->where('usuario_id', $this->usuario(self::JOSE)->id)->firstOrFail();

        $d = app(HistorialClienteAction::class)->ejecutar('cliente_directo', $jose->id);
        $this->assertSame($jose->nombre, $d['perfil']['nombre']);
        foreach (['pedidos', 'pagos', 'vales', 'cambios', 'pendientes', 'ventas'] as $clave) {
            $this->assertIsArray($d[$clave]);
        }
        $this->assertEquals(array_sum(array_column($d['pendientes'], 'pendiente')), $d['resumen']['saldo_por_cobrar']);

        $this->get(route('clientes.historial.show', ['cliente_directo', $jose->id]))->assertOk()->assertSee($jose->nombre);
        $this->get(route('clientes.historial'))->assertOk();

        $this->comoPanel(self::OTRA_ADMIN);
        $this->get(route('clientes.historial.show', ['cliente_directo', $jose->id]))->assertNotFound();
    }

    public function test_el_empleado_sin_permiso_no_ve_el_historial(): void
    {
        $this->staffDe(self::EMPLEADO)->update(['permisos_finos' => [PermisoModuloService::CLIENTES_VER => false]]);
        $this->comoPanel(self::EMPLEADO);
        $this->get(route('clientes.historial'))->assertForbidden();
    }

    public function test_la_vista_previa_marca_errores_y_solo_importa_los_validos(): void
    {
        $this->comoPanel(self::ADMIN);
        $existente = ClienteDirecto::query()->whereNotNull('email')->firstOrFail();

        $ruta = $this->csv("Nombre,Teléfono,Correo,Dirección\n"
            ."Ana Nueva,55 1234 5678,ana.nueva@ejemplo.com,Centro\n"
            .",5599999999,sin.nombre@ejemplo.com,\n"
            ."Correo Malo,5511112222,no-es-correo,\n"
            ."Duplicada,5533334444,{$existente->email},\n"
            ."Repetida,5512345678,otra@ejemplo.com,\n"
            ."\n"
            ."Beto Nuevo,,beto@ejemplo.com,\n");

        $accion = app(ImportarClientesAction::class);
        $filas = $accion->analizar($ruta, 'clientes.csv');

        $this->assertCount(6, $filas);
        $errores = collect($filas)->keyBy('fila')->map(fn ($f) => implode(' ', $f['errores']));
        $this->assertSame('', $errores[2]);
        $this->assertStringContainsString('Falta el nombre', $errores[3]);
        $this->assertStringContainsString('correo no es válido', $errores[4]);
        $this->assertStringContainsString('Ya existe', $errores[5]);
        $this->assertStringContainsString('repetido en el archivo (fila 2)', $errores[6]);
        $this->assertSame('', $errores[8]);

        $this->assertStringContainsString('Falta el nombre', $accion->reporteErrores($filas));

        $antes = ClienteDirecto::query()->count();
        $this->assertSame(2, $accion->importar($filas));
        $this->assertSame($antes + 2, ClienteDirecto::query()->count());
        $this->assertSame('5512345678', ClienteDirecto::query()->where('email', 'ana.nueva@ejemplo.com')->value('telefono'));
        $this->assertTrue(Auditoria::withoutGlobalScopes()->where('accion', 'clientes.importados')->exists());

        // Volver a importar lo mismo no duplica.
        $this->assertSame(0, $accion->importar($filas));
    }

    public function test_importa_excel_desde_la_pantalla(): void
    {
        $this->comoPanel(self::ADMIN);
        $libro = new Spreadsheet;
        $libro->getActiveSheet()->fromArray([['nombre', 'telefono', 'email'], ['Carla Excel', '5587654321', 'carla@ejemplo.com']]);
        $ruta = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($libro))->save($ruta);

        Livewire::test('clientes.importar')
            ->set('archivo', UploadedFile::fake()->createWithContent('clientes.xlsx', file_get_contents($ruta)))
            ->assertSet('errorMsg', '')
            ->assertCount('filas', 1)
            ->call('importar')
            ->assertSee('Se importó 1 cliente.');

        $this->assertTrue(ClienteDirecto::query()->where('email', 'carla@ejemplo.com')->exists());
    }

    public function test_importar_requiere_permiso_y_queda_en_su_distribuidora(): void
    {
        $this->comoPanel(self::EMPLEADO);
        $this->get(route('clientes.importar'))->assertForbidden();

        $this->comoPanel(self::OTRA_ADMIN);
        $filas = app(ImportarClientesAction::class)->analizar($this->csv("nombre,email\nDe Boutique,boutique.nuevo@ejemplo.com\n"), 'a.csv');
        app(ImportarClientesAction::class)->importar($filas);
        $this->assertSame($this->boutique()->id, (int) ClienteDirecto::withoutGlobalScopes()->where('email', 'boutique.nuevo@ejemplo.com')->value('distribuidora_id'));
    }
}
