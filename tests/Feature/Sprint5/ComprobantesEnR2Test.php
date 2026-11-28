<?php

namespace Tests\Feature\Sprint5;

use App\Models\ComprobanteTransferencia;
use App\Models\ConfiguracionDistribuidora;
use App\Models\Pedido;
use App\Models\Revendedor;
use App\Models\Sucursal;
use Database\Seeders\AdminGeneralSeeder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\Concerns\AyudasFuncionesCompletas;
use Tests\TestCase;

/**
 * TG-411 (K46): los comprobantes de transferencia van al disco de
 * COMPROBANTES_DISK (R2 privado en producción) bajo comprobantes/. Cada fila
 * guarda su disco y el archivo lo sigue sirviendo el servidor.
 */
class ComprobantesEnR2Test extends TestCase
{
    use AyudasFuncionesCompletas;
    use RefreshDatabase;

    protected bool $seed = true;

    private const DISCO = 'catalogos';

    protected function setUp(): void
    {
        parent::setUp();

        config(['comprobantes.disco' => self::DISCO]);
        Storage::fake(self::DISCO);
        Storage::fake('local');
        Storage::fake('comprobantes');
    }

    public function test_sin_variable_usa_el_disco_local(): void
    {
        $this->assertSame('local', (require config_path('comprobantes.php'))['disco']);
        $this->assertSame('comprobantes', (require config_path('comprobantes.php'))['prefijo']);
    }

    public function test_el_comprobante_se_sube_al_disco_configurado_con_el_prefijo(): void
    {
        $pedido = $this->pedidoDeMaria();

        $this->comoApi(self::MARIA);
        $this->post("/api/pedidos/{$pedido->id}/comprobante-transferencia", [
            'comprobante' => UploadedFile::fake()->createWithContent('recibo.pdf', '%PDF-1.4 recibo'),
        ])->assertCreated()
            ->assertJsonPath('data.nombre', 'recibo.pdf')
            ->assertJsonMissingPath('data.ruta')
            ->assertJsonMissingPath('data.url');

        $comprobante = ComprobanteTransferencia::withoutGlobalScopes()->where('pedido_id', $pedido->id)->firstOrFail();

        $this->assertSame(self::DISCO, $comprobante->disco);
        $this->assertMatchesRegularExpression(
            '#^comprobantes/'.$pedido->distribuidora_id.'/'.$pedido->id.'/[0-9a-f-]{36}\.pdf$#',
            $comprobante->ruta
        );
        Storage::disk(self::DISCO)->assertExists($comprobante->ruta);
        $this->assertSame('%PDF-1.4 recibo', Storage::disk(self::DISCO)->get($comprobante->ruta));
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('comprobantes')->allFiles());
    }

    public function test_reemplazar_deja_una_fila_y_borra_el_archivo_anterior(): void
    {
        $pedido = $this->pedidoDeMaria();
        $this->comoApi(self::MARIA);

        $this->subir($pedido, 'primero.pdf', '%PDF-1.4 primero')->assertCreated();
        $rutaAnterior = ComprobanteTransferencia::withoutGlobalScopes()->where('pedido_id', $pedido->id)->value('ruta');

        $this->subir($pedido, 'segundo.png', 'png-segundo')->assertCreated();

        $filas = ComprobanteTransferencia::withoutGlobalScopes()->where('pedido_id', $pedido->id)->get();
        $this->assertCount(1, $filas);
        $this->assertSame('segundo.png', $filas[0]->nombre_original);
        $this->assertNotSame($rutaAnterior, $filas[0]->ruta);
        $this->assertStringStartsWith('comprobantes/'.$pedido->distribuidora_id.'/'.$pedido->id.'/', $filas[0]->ruta);

        Storage::disk(self::DISCO)->assertMissing($rutaAnterior);
        Storage::disk(self::DISCO)->assertExists($filas[0]->ruta);
        $this->assertCount(1, Storage::disk(self::DISCO)->allFiles());
    }

    public function test_un_comprobante_viejo_del_disco_local_se_reemplaza_en_el_nuevo(): void
    {
        $pedido = $this->pedidoDeMaria();
        $rutaVieja = $pedido->distribuidora_id.'/'.$pedido->id.'/viejo.pdf';
        Storage::disk('comprobantes')->put($rutaVieja, 'viejo');
        $this->comprobanteViejo($pedido, $rutaVieja);

        $this->comoApi(self::MARIA);
        $this->get("/api/pedidos/{$pedido->id}/comprobante-transferencia")->assertOk();

        $this->subir($pedido, 'nuevo.pdf', '%PDF-1.4 nuevo')->assertCreated();

        $comprobante = ComprobanteTransferencia::withoutGlobalScopes()->where('pedido_id', $pedido->id)->firstOrFail();
        $this->assertSame(self::DISCO, $comprobante->disco);
        $this->assertStringStartsWith('comprobantes/', $comprobante->ruta);
        Storage::disk('comprobantes')->assertMissing($rutaVieja);
        Storage::disk(self::DISCO)->assertExists($comprobante->ruta);
    }

    public function test_si_no_se_puede_borrar_el_anterior_el_nuevo_queda_guardado(): void
    {
        Exceptions::fake();
        $pedido = $this->pedidoDeMaria();
        $this->comprobanteViejo($pedido, $pedido->distribuidora_id.'/'.$pedido->id.'/viejo.pdf');

        $falla = Mockery::mock(Filesystem::class);
        $falla->shouldReceive('delete')->once()->andThrow(new RuntimeException('R2 no responde'));
        Storage::set('comprobantes', $falla);

        $this->comoApi(self::MARIA);
        $this->subir($pedido, 'nuevo.pdf', '%PDF-1.4 nuevo')->assertCreated();

        $comprobante = ComprobanteTransferencia::withoutGlobalScopes()->where('pedido_id', $pedido->id)->firstOrFail();
        $this->assertSame('nuevo.pdf', $comprobante->nombre_original);
        Storage::disk(self::DISCO)->assertExists($comprobante->ruta);
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_lo_ven_el_cliente_y_su_distribuidora_desde_el_servidor(): void
    {
        $pedido = $this->pedidoDeMaria();
        $this->comoApi(self::MARIA);
        $this->subir($pedido, 'recibo.pdf', '%PDF-1.4 recibo')->assertCreated();

        $respuesta = $this->get("/api/pedidos/{$pedido->id}/comprobante-transferencia")->assertOk();
        $this->assertSame('%PDF-1.4 recibo', $respuesta->streamedContent());
        $this->assertStringContainsString('attachment', (string) $respuesta->headers->get('Content-Disposition'));
        $this->assertStringContainsString('recibo.pdf', (string) $respuesta->headers->get('Content-Disposition'));

        foreach ([self::ADMIN, self::EMPLEADO] as $email) {
            $this->enPanel($email);
            $this->assertSame(
                '%PDF-1.4 recibo',
                $this->get(route('pedidos.comprobante-transferencia', $pedido->id))->assertOk()->streamedContent()
            );
        }
    }

    public function test_sin_autorizacion_no_se_ve(): void
    {
        $pedido = $this->pedidoDeMaria();
        $this->comoApi(self::MARIA);
        $this->subir($pedido, 'recibo.pdf', '%PDF-1.4 recibo')->assertCreated();

        // En el panel, solo el personal de la distribuidora: a un cliente
        // solo.personal le cierra la sesión y lo manda al login.
        foreach ([self::MARIA, self::JOSE] as $email) {
            $this->enPanel($email);
            $this->get(route('pedidos.comprobante-transferencia', $pedido->id))->assertRedirect(route('login'));
            $this->assertGuest('web');
        }

        // Personal sin distribuidora (admin general): 403.
        $this->enPanel(AdminGeneralSeeder::EMAIL);
        $this->get(route('pedidos.comprobante-transferencia', $pedido->id))->assertForbidden();

        // Otro cliente u otra distribuidora: el pedido ni siquiera existe para ellos.
        $this->comoApi(self::JOSE);
        $this->get("/api/pedidos/{$pedido->id}/comprobante-transferencia")->assertNotFound();

        $this->comoApi(self::OTRA_ADMIN);
        $this->get("/api/pedidos/{$pedido->id}/comprobante-transferencia")->assertNotFound();

        $this->enPanel(self::OTRA_ADMIN);
        $this->get(route('pedidos.comprobante-transferencia', $pedido->id))->assertNotFound();
    }

    public function test_un_comprobante_viejo_que_se_perdio_da_404(): void
    {
        $pedido = $this->pedidoDeMaria();
        $this->comprobanteViejo($pedido, $pedido->distribuidora_id.'/'.$pedido->id.'/perdido.pdf');

        $this->comoApi(self::MARIA);
        $this->get("/api/pedidos/{$pedido->id}/comprobante-transferencia")->assertNotFound();
    }

    /** comoApi deja Sanctum como guard por omisión; el panel usa web. */
    private function enPanel(string $email): void
    {
        $this->app['auth']->shouldUse('web');
        $this->comoPanel($email);
    }

    private function subir(Pedido $pedido, string $nombre, string $contenido)
    {
        return $this->post("/api/pedidos/{$pedido->id}/comprobante-transferencia", [
            'comprobante' => UploadedFile::fake()->createWithContent($nombre, $contenido),
        ]);
    }

    /** Fila de antes de TG-411: disco 'comprobantes' y ruta sin prefijo. */
    private function comprobanteViejo(Pedido $pedido, string $ruta): void
    {
        ComprobanteTransferencia::withoutGlobalScopes()->forceCreate([
            'distribuidora_id' => $pedido->distribuidora_id,
            'pedido_id' => $pedido->id,
            'disco' => 'comprobantes',
            'ruta' => $ruta,
            'nombre_original' => 'viejo.pdf',
            'mime' => 'application/pdf',
            'tamano' => 5,
            'subido_por_usuario_id' => $this->usuario(self::MARIA)->id,
        ]);
    }

    private function pedidoDeMaria(): Pedido
    {
        $this->comoApi(self::MARIA);
        $maria = $this->usuario(self::MARIA);

        $pedidoId = (int) $this->postJson('/api/pedidos', [
            'tipo' => 'revendedor',
            'propietario_id' => Revendedor::where('usuario_id', $maria->id)->value('id'),
            'sucursal_id' => Sucursal::withoutGlobalScopes()
                ->where('distribuidora_id', $this->ramirez()->id)
                ->where('es_principal', true)
                ->value('id'),
        ])->assertCreated()->json('data.id');

        $variante = $this->variantePedible();

        $this->postJson("/api/pedidos/{$pedidoId}/lineas", [
            'producto_campana_id' => $variante->producto_campana_id,
            'variante_id' => $variante->variante_id,
            'cantidad' => 1,
        ])->assertCreated();

        $this->postJson("/api/pedidos/{$pedidoId}/enviar")->assertOk();

        ConfiguracionDistribuidora::withoutGlobalScopes()
            ->where('distribuidora_id', $this->ramirez()->id)
            ->update([
                'banco' => 'BBVA',
                'clabe' => '012180001234567899',
                'titular_cuenta' => 'Calzados Ramírez',
                'acepta_transferencias' => true,
            ]);

        return Pedido::withoutGlobalScopes()->findOrFail($pedidoId);
    }
}
