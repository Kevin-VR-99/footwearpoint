<?php

namespace Tests\Feature\Auditoria;

use App\Exceptions\OperacionInvalidaException;
use App\Models\Auditoria;
use App\Models\ProductoCampana;
use App\Models\ProductoImagen;
use App\Models\Usuario;
use App\Services\Catalogo\CatalogoVisible;
use App\Services\Catalogo\GestionarCategoriaProductoAction;
use App\Services\Catalogo\GestionarImagenProductoCampanaAction;
use App\Services\Catalogo\GestionarProductoCampanaAction;
use App\Services\Distribuidora\GestionarOfertaDistribuidoraAction;
use App\Services\Stock\StockService;
use App\Services\VentaDirecta\RegistrarVentaDirectaService;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\AdminGeneralSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TG-267 (U7, E17-03) - La auditoría también cubre stock, ventas de
 * mostrador, precios de la distribuidora y cambios al catálogo compartido,
 * y el admin general tiene su propia pantalla de solo lectura.
 */
class AuditoriaAmpliadaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const ADMIN = 'admin@calzadosramirez.test';
    private const EMPLEADO = 'empleado@calzadosramirez.test';

    private int $desde = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        // El seeder demo ya deja algunos registros: solo cuentan los de la prueba.
        $this->desde = (int) Auditoria::withoutGlobalScopes()->max('id');
    }

    private function como(string $email): Usuario
    {
        $this->app['auth']->forgetGuards();
        $usuario = Usuario::where('email', $email)->firstOrFail();
        $this->actingAs($usuario);
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        app(PermissionRegistrar::class)->setPermissionsTeamId(Tenant::id() ?? 0);

        return $usuario;
    }

    private function registros(string $accion)
    {
        return Auditoria::withoutGlobalScopes()->where('id', '>', $this->desde)->where('accion', $accion)->orderBy('id')->get();
    }

    /** Algo que el seeder dejó con existencia para vender. */
    private function renglonDisponible(): array
    {
        $renglon = Livewire::test('punto-venta.index')->instance()->disponibles->first();
        $this->assertNotNull($renglon, 'El seeder demo no dejó nada con existencia.');

        return $renglon;
    }

    /**
     * Directo a la tabla: con Eloquent, el alta completaría la distribuidora
     * del usuario y no se podría dejar un registro sin distribuidora.
     */
    private function auditoriaDe(?int $distribuidoraId, string $accion, ?int $usuarioId = null): void
    {
        DB::table('auditorias')->insert([
            'usuario_id' => $usuarioId,
            'distribuidora_id' => $distribuidoraId,
            'accion' => $accion,
            'entidad_tipo' => 'prueba',
            'entidad_id' => 1,
            'ip_origen' => '127.0.0.1',
            'created_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------
    // Stock
    // ------------------------------------------------------------------

    public function test_la_entrada_de_stock_queda_con_la_existencia_antes_y_despues(): void
    {
        $empleado = $this->como(self::EMPLEADO);
        $varianteId = $this->renglonDisponible()['variante_id'];

        $movimiento = app(StockService::class)->registrarEntrada($varianteId, 5, 'Llegó pedido de fábrica');

        $registro = $this->registros('stock.entrada')->sole();
        $this->assertSame($empleado->id, $registro->usuario_id);
        $this->assertSame(Tenant::id(), $registro->distribuidora_id);
        $this->assertSame('stock_local', $registro->entidad_tipo);
        $this->assertSame($movimiento->existencia_anterior, $registro->datos_previos['cantidad_disponible']);
        $this->assertSame($movimiento->existencia_posterior, $registro->datos_nuevos['cantidad_disponible']);
        $this->assertSame($varianteId, $registro->datos_nuevos['variante_id']);
        $this->assertSame('Llegó pedido de fábrica', $registro->datos_nuevos['motivo']);
    }

    public function test_el_ajuste_queda_con_su_motivo_y_uno_rechazado_no_deja_nada(): void
    {
        $this->como(self::EMPLEADO);
        $varianteId = $this->renglonDisponible()['variante_id'];

        app(StockService::class)->registrarAjuste($varianteId, 'ajuste_negativo', 1, 'Merma');

        $registro = $this->registros('stock.ajuste')->sole();
        $this->assertSame('ajuste_negativo', $registro->datos_nuevos['tipo']);
        $this->assertSame('Merma', $registro->datos_nuevos['motivo']);
        $this->assertSame(
            $registro->datos_previos['cantidad_disponible'] - 1,
            $registro->datos_nuevos['cantidad_disponible']
        );

        try {
            app(StockService::class)->registrarAjuste($varianteId, 'ajuste_negativo', 100000, 'Demasiado');
            $this->fail('El ajuste debió rechazarse.');
        } catch (OperacionInvalidaException) {
        }

        $this->assertCount(1, $this->registros('stock.ajuste'));
    }

    // ------------------------------------------------------------------
    // Venta de mostrador
    // ------------------------------------------------------------------

    public function test_la_venta_de_mostrador_deja_un_solo_registro_con_sus_productos(): void
    {
        $this->como(self::EMPLEADO);
        $renglon = $this->renglonDisponible();

        $venta = app(RegistrarVentaDirectaService::class)->registrar([[
            'variante_id'         => $renglon['variante_id'],
            'producto_campana_id' => $renglon['producto_campana_id'],
            'cantidad'            => 1,
        ]], 'efectivo')->venta;

        $registro = $this->registros('venta_mostrador.registrada')->sole();
        $this->assertSame('venta_directa', $registro->entidad_tipo);
        $this->assertSame($venta->id, $registro->entidad_id);
        $this->assertSame($venta->folio, $registro->datos_nuevos['folio']);
        $this->assertSame('efectivo', $registro->datos_nuevos['metodo_pago']);
        $this->assertCount(1, $registro->datos_nuevos['productos']);
        $this->assertSame($renglon['variante_id'], $registro->datos_nuevos['productos'][0]['variante_id']);
        $this->assertSame(1, $registro->datos_nuevos['productos'][0]['cantidad']);

        // El descuento de stock va dentro de la venta, no aparte.
        $this->assertCount(0, Auditoria::withoutGlobalScopes()->where('id', '>', $this->desde)->where('accion', 'like', 'stock.%')->get());
    }

    // ------------------------------------------------------------------
    // Precios de la distribuidora
    // ------------------------------------------------------------------

    public function test_los_cambios_de_precio_y_visibilidad_quedan_con_antes_y_despues(): void
    {
        $this->como(self::ADMIN);

        // Uno que NO tenga precio de mayoreo todavía: la prueba comprueba que
        // el "antes" del primer cambio sea null. Tomar el primero de la lista
        // la hacía depender de en qué orden salen los productos, y al cambiar
        // la visibilidad a temporadas (TG-282) el primero pasó a ser uno que
        // la semilla ya trae con $1,990 de mayoreo.
        $yaConPrecio = \App\Models\OfertaDistribuidora::whereNotNull('precio_mayorista')
            ->pluck('producto_campana_id');

        // Y con orden explícito: la consulta no lo trae, así que "el primero"
        // dependía del plan de ejecución y la prueba pasaba sola pero fallaba
        // dentro de la suite completa.
        $productoCampana = app(CatalogoVisible::class)->consulta()
            ->whereNotIn('producto_campana.id', $yaConPrecio)
            ->orderBy('producto_campana.id')
            ->firstOrFail();

        $precio = round((float) $productoCampana->precio_publico - 10, 2);
        $ofertas = app(GestionarOfertaDistribuidoraAction::class);

        $ofertas->ponerPrecioMayorista($productoCampana->id, $precio);
        $ofertas->ponerPrecioMayorista($productoCampana->id, $precio); // mismo precio: no cuenta
        $ofertas->quitarPrecioMayorista($productoCampana->id);
        $ofertas->ocultar($productoCampana->id);
        $ofertas->mostrar($productoCampana->id);

        $cambio = $this->registros('oferta.precio_mayorista_actualizado')->sole();
        $this->assertSame('producto_campana', $cambio->entidad_tipo);
        $this->assertSame($productoCampana->id, $cambio->entidad_id);
        $this->assertNull($cambio->datos_previos['precio_mayorista']);
        $this->assertEquals($precio, $cambio->datos_nuevos['precio_mayorista']);

        $restablecido = $this->registros('oferta.precio_mayorista_restablecido')->sole();
        $this->assertEquals($precio, $restablecido->datos_previos['precio_mayorista']);
        $this->assertNull($restablecido->datos_nuevos['precio_mayorista']);

        $this->assertFalse($this->registros('oferta.producto_ocultado')->sole()->datos_nuevos['publicado']);
        $this->assertTrue($this->registros('oferta.producto_mostrado')->sole()->datos_nuevos['publicado']);
    }

    // ------------------------------------------------------------------
    // Catálogo compartido
    // ------------------------------------------------------------------

    public function test_el_cambio_de_precio_en_el_catalogo_guarda_solo_lo_que_cambio(): void
    {
        $admin = $this->como(AdminGeneralSeeder::EMAIL);
        $productoCampana = ProductoCampana::withoutGlobalScopes()->orderBy('id')->firstOrFail();
        $anterior = (float) $productoCampana->precio_publico;

        app(GestionarProductoCampanaAction::class)->actualizar($productoCampana, ['precio_publico' => $anterior + 100]);

        $registro = $this->registros('catalogo.publicacion.actualizada')->sole();
        $this->assertNull($registro->distribuidora_id);
        $this->assertSame($admin->id, $registro->usuario_id);
        $this->assertSame(['precio_publico'], array_keys($registro->datos_previos));
        $this->assertEquals($anterior, (float) $registro->datos_previos['precio_publico']);
        $this->assertEquals($anterior + 100, (float) $registro->datos_nuevos['precio_publico']);

        // Guardar sin cambiar nada no deja registro.
        app(GestionarProductoCampanaAction::class)->actualizar($productoCampana->fresh(), ['precio_publico' => $anterior + 100]);
        $this->assertCount(1, $this->registros('catalogo.publicacion.actualizada'));
    }

    public function test_crear_una_categoria_queda_registrado(): void
    {
        $this->como(AdminGeneralSeeder::EMAIL);

        $categoria = app(GestionarCategoriaProductoAction::class)->crear(['nombre' => 'Botas de lluvia']);

        $registro = $this->registros('catalogo.categoria.creada')->sole();
        $this->assertSame($categoria->id, $registro->entidad_id);
        $this->assertNull($registro->datos_previos);
        $this->assertSame('Botas de lluvia', $registro->datos_nuevos['nombre']);
    }

    public function test_agregar_y_quitar_imagenes_queda_registrado(): void
    {
        Storage::fake('s3');
        $this->como(AdminGeneralSeeder::EMAIL);
        $productoCampana = ProductoCampana::withoutGlobalScopes()->orderBy('id')->firstOrFail();
        $acciones = app(GestionarImagenProductoCampanaAction::class);

        $imagen = $acciones->agregar($productoCampana, UploadedFile::fake()->create('tenis.jpg', 20, 'image/jpeg'));
        $acciones->eliminar(ProductoImagen::findOrFail($imagen->id));

        $agregada = $this->registros('catalogo.imagen.agregada')->sole();
        $this->assertSame($imagen->id, $agregada->entidad_id);
        $this->assertSame($productoCampana->id, $agregada->datos_nuevos['producto_campana_id']);

        $eliminada = $this->registros('catalogo.imagen.eliminada')->sole();
        $this->assertSame($imagen->url, $eliminada->datos_previos['url']);
        $this->assertNull($eliminada->datos_nuevos);
    }

    // ------------------------------------------------------------------
    // Pantallas
    // ------------------------------------------------------------------

    public function test_el_admin_general_ve_solo_lo_que_no_es_de_ninguna_distribuidora(): void
    {
        $this->como(self::ADMIN);
        $distribuidoraId = Tenant::id();
        $this->auditoriaDe($distribuidoraId, 'stock.entrada');
        $this->auditoriaDe(null, 'catalogo.producto.actualizado');

        $this->como(AdminGeneralSeeder::EMAIL);

        $this->get('/admin/auditoria')
            ->assertOk()
            ->assertSee('Auditoría')
            ->assertSee('Producto modificado')
            ->assertSee('catalogo.producto.actualizado')
            ->assertDontSee('Entrada de stock');
    }

    public function test_los_roles_de_distribuidora_no_pueden_abrir_la_auditoria_del_admin(): void
    {
        $this->como(self::ADMIN);
        $this->get('/admin/auditoria')->assertForbidden();

        $this->como(self::EMPLEADO);
        $this->get('/admin/auditoria')->assertForbidden();
    }

    public function test_la_distribuidora_no_ve_los_registros_del_catalogo_compartido(): void
    {
        $this->como(self::ADMIN);
        $this->auditoriaDe(Tenant::id(), 'venta_mostrador.registrada');
        $this->auditoriaDe(null, 'catalogo.marca.actualizada');

        Livewire::test('auditoria.index')
            ->assertOk()
            ->assertSee('Venta de mostrador')
            ->assertDontSee('Marca modificada');
    }

    public function test_el_filtro_por_area_y_por_usuario(): void
    {
        $admin = $this->como(self::ADMIN);
        $empleado = Usuario::where('email', self::EMPLEADO)->firstOrFail();
        $this->auditoriaDe(Tenant::id(), 'stock.ajuste', $empleado->id);
        $this->auditoriaDe(Tenant::id(), 'pedido.cambio_estado', $admin->id);

        $pantalla = Livewire::test('auditoria.index')
            ->assertSee('Ajuste de stock')
            ->assertSee('Cambio de estado de pedido');

        $acciones = fn () => $pantalla->instance()->registros->pluck('accion')->all();

        $pantalla->set('filtro_area', 'Stock');
        $this->assertSame(['stock.ajuste'], $acciones());

        $pantalla->set('filtro_area', '')->set('filtro_usuario', (string) $admin->id);
        $this->assertSame(['pedido.cambio_estado'], $acciones());
    }
}
