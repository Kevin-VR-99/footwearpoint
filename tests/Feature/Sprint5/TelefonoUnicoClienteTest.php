<?php

namespace Tests\Feature\Sprint5;

use App\Models\Auditoria;
use App\Models\ClienteDirecto;
use App\Models\Pedido;
use App\Models\Usuario;
use App\Services\Cliente\DepurarTelefonosClientesDirectos;
use App\Support\TelefonoCliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Concerns\AyudasFuncionesCompletas;
use Tests\TestCase;

/** S5-55 y S5-56: teléfono de 10 dígitos, único por distribuidora. */
class TelefonoUnicoClienteTest extends TestCase
{
    use AyudasFuncionesCompletas;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_normaliza_a_los_ultimos_10_digitos(): void
    {
        $this->assertSame('5512345678', TelefonoCliente::normalizar('+52 55 1234-5678'));
        $this->assertSame('5512345678', TelefonoCliente::normalizar('5512345678'));
        $this->assertNull(TelefonoCliente::normalizar('12345'));
        $this->assertNull(TelefonoCliente::normalizar('  -  '));
        $this->assertNull(TelefonoCliente::normalizar(null));
    }

    public function test_el_comando_en_prueba_no_escribe_y_confirmar_deja_null_al_duplicado(): void
    {
        $gana = ClienteDirecto::query()->where('nombre', 'José Hernández')->firstOrFail();
        $pierde = ClienteDirecto::query()->where('nombre', 'Ana García')->firstOrFail();
        DB::table('clientes_directos')->where('id', $gana->id)->update([
            'telefono' => '55 1234-5678',
            'updated_at' => now(),
        ]);
        DB::table('clientes_directos')->where('id', $pierde->id)->update([
            'telefono' => '525512345678',
            'updated_at' => now()->subDay(),
        ]);
        Pedido::withoutGlobalScopes()->where('cliente_directo_id', $pierde->id)->delete();

        Artisan::call('clientes:depurar-telefonos');
        $this->assertSame('55 1234-5678', $gana->fresh()->telefono);

        Artisan::call('clientes:depurar-telefonos', ['--confirmar' => true]);

        $this->assertSame('5512345678', $gana->fresh()->telefono);
        $this->assertNull($pierde->fresh()->telefono);
        $this->assertFalse((bool) $pierde->fresh()->telefono_por_recapturar);
        $this->assertTrue(
            Auditoria::query()->where('accion', DepurarTelefonosClientesDirectos::ACCION_AUDITORIA)
                ->where('entidad_id', $pierde->id)->exists()
        );
        $auditoria = Auditoria::query()->where('entidad_id', $pierde->id)
            ->where('accion', DepurarTelefonosClientesDirectos::ACCION_AUDITORIA)->first();
        $this->assertSame('525512345678', $auditoria->datos_previos['telefono']);
    }

    public function test_la_migracion_depura_duplicados_y_puede_repetirse(): void
    {
        $this->comoPanel(self::ADMIN);
        $distribuidoraId = $this->ramirez()->id;
        $usuario = Usuario::create([
            'nombre' => 'Recaptura S556',
            'email' => 'recaptura-s556@cliente.test',
            'password' => 'secreto-prueba',
            'estado' => 'activo',
        ]);
        $ganaId = DB::table('clientes_directos')->insertGetId([
            'distribuidora_id' => $distribuidoraId,
            'nombre' => 'Gana migracion',
            'telefono' => '5599990001',
            'estado' => 'activo',
            'telefono_por_recapturar' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $pierdeId = DB::table('clientes_directos')->insertGetId([
            'distribuidora_id' => $distribuidoraId,
            'usuario_id' => $usuario->id,
            'nombre' => 'Pierde migracion',
            'telefono' => '525599990001',
            'estado' => 'activo',
            'telefono_por_recapturar' => false,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $migracion = require base_path('database/migrations/2026_11_12_130000_telefono_unico_clientes_directos.php');
        $migracion->up();
        $migracion->up();

        $this->assertSame('5599990001', DB::table('clientes_directos')->where('id', $ganaId)->value('telefono'));
        $pierde = DB::table('clientes_directos')->where('id', $pierdeId)->first();
        $this->assertNull($pierde->telefono);
        $this->assertSame(1, (int) $pierde->telefono_por_recapturar);
        $this->assertSame(
            '525599990001',
            Auditoria::query()->where('accion', DepurarTelefonosClientesDirectos::ACCION_AUDITORIA)
                ->where('entidad_id', $pierdeId)->firstOrFail()->datos_previos['telefono']
        );
        $this->assertTrue(
            collect(Schema::getIndexes('clientes_directos'))->pluck('name')->contains('uq_clientes_directos_telefono')
        );
    }

    public function test_el_indice_unico_existe_y_null_no_choca(): void
    {
        $this->comoPanel(self::ADMIN);
        $this->assertTrue(Schema::hasColumn('clientes_directos', 'telefono_por_recapturar'));
        $indices = collect(Schema::getIndexes('clientes_directos'))->pluck('name');
        $this->assertTrue($indices->contains('uq_clientes_directos_telefono'));

        $uno = ClienteDirecto::query()->create(['nombre' => 'Sin tel uno', 'telefono' => null, 'estado' => 'activo']);
        $dos = ClienteDirecto::query()->create(['nombre' => 'Sin tel dos', 'telefono' => null, 'estado' => 'activo']);
        $this->assertNull($uno->telefono);
        $this->assertNull($dos->telefono);
    }

    public function test_el_panel_rechaza_un_telefono_corto_o_repetido(): void
    {
        $this->comoPanel(self::ADMIN);

        Livewire::test('distribuidora.clientes')
            ->set('cliente_nombre', 'Nuevo telefono')
            ->set('cliente_telefono', '123')
            ->call('guardarCliente')
            ->assertHasErrors('cliente_telefono');

        Livewire::test('distribuidora.clientes')
            ->set('cliente_nombre', 'Nuevo telefono')
            ->set('cliente_telefono', '+52 963 555 4444')
            ->call('guardarCliente')
            ->assertHasErrors('cliente_telefono');
    }

    public function test_primera_compra_crea_el_borrador_y_el_repetido_ofrece_al_existente(): void
    {
        $this->comoPanel(self::ADMIN);

        $pantalla = Livewire::test('pedidos.create')
            ->assertSee('¿Primera compra?')
            ->set('primeraCompra', 'si')
            ->set('altaNombre', 'Cliente nuevo S556')
            ->set('altaTelefono', '55 0000-1111')
            ->call('crearPrimeraCompra')
            ->assertSet('errorMsg', '');

        $cliente = ClienteDirecto::query()->where('nombre', 'Cliente nuevo S556')->firstOrFail();
        $this->assertSame('5500001111', $cliente->telefono);
        $this->assertNotNull($pantalla->get('pedidoId'));

        Livewire::test('pedidos.create')
            ->set('primeraCompra', 'si')
            ->set('altaNombre', 'Otro')
            ->set('altaTelefono', '5500001111')
            ->call('crearPrimeraCompra')
            ->assertSet('primeraCompra', 'no')
            ->assertSee('Se usará a Cliente nuevo S556');

        $this->assertSame(1, ClienteDirecto::query()->where('telefono', '5500001111')->count());
    }

    public function test_sin_nombre_o_sin_telefono_no_se_guarda_y_el_mayorista_no_cambia(): void
    {
        $this->comoPanel(self::ADMIN);

        Livewire::test('pedidos.create')
            ->set('primeraCompra', 'si')
            ->set('altaNombre', '')
            ->set('altaTelefono', '')
            ->call('crearPrimeraCompra')
            ->assertHasErrors(['altaNombre', 'altaTelefono']);

        $pantalla = Livewire::test('pedidos.create')->set('tipo', 'revendedor')->assertDontSee('¿Primera compra?');
        $id = $pantalla->instance()->propietarios->first()->id;
        $pantalla->set('propietario_id', (string) $id)->call('crearBorrador')->assertSet('errorMsg', '');
    }

    public function test_el_buscador_encuentra_por_telefono(): void
    {
        $this->comoPanel(self::ADMIN);
        $cliente = ClienteDirecto::query()->whereNotNull('telefono')->firstOrFail();

        $pantalla = Livewire::test('pedidos.create')
            ->set('busquedaPropietario', $cliente->telefono);

        $this->assertTrue($pantalla->instance()->propietariosFiltrados->pluck('id')->contains($cliente->id));
    }
}
