<?php

namespace Tests\Feature\Catalogo;

use App\Models\Campana;
use App\Models\Distribuidora;
use App\Models\Linea;
use App\Models\Marca;
use App\Models\PlanSuscripcion;
use App\Models\Suscripcion;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TG-158 ? Cupo de l?neas desde el Livewire de cat?logo.
 *
 * El l?mite del plan es por L?NEAS activas (no por marcas). Se prueba
 * catalogo.lineas (pesta?a del panel tras TG-157).
 */
class LineasCupoLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const ADMIN = 'admin@calzadosramirez.test';

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        $this->actingAs(Usuario::where('email', self::ADMIN)->firstOrFail());
    }

    private function distribuidora(): Distribuidora
    {
        return Distribuidora::where('slug', 'calzados-ramirez')->firstOrFail();
    }

    private function asegurarSuscripcionConLimite(int $limite): Suscripcion
    {
        $plan = PlanSuscripcion::firstOrCreate(
            ['nombre' => 'Plan prueba cupo TG-158'],
            [
                'descripcion' => 'Solo para tests',
                'precio_base_mensual' => 1,
                'lineas_incluidas' => $limite,
                'precio_linea_extra' => 0,
                'activo' => true,
            ]
        );

        $suscripcion = Suscripcion::withoutGlobalScopes()
            ->where('distribuidora_id', $this->distribuidora()->id)
            ->where('estado', 'activa')
            ->first();

        if ($suscripcion) {
            $suscripcion->update([
                'plan_id' => $plan->id,
                'lineas_incluidas_contratadas' => $limite,
                'lineas_extra_contratadas' => 0,
            ]);

            return $suscripcion->fresh();
        }

        return Suscripcion::withoutGlobalScopes()->create([
            'distribuidora_id' => $this->distribuidora()->id,
            'plan_id' => $plan->id,
            'fecha_inicio' => now()->toDateString(),
            'fecha_fin' => now()->addYear()->toDateString(),
            'estado' => 'activa',
            'precio_base_contratado' => 1,
            'lineas_incluidas_contratadas' => $limite,
            'precio_linea_extra_contratado' => 0,
            'lineas_extra_contratadas' => 0,
            'renovacion_automatica' => false,
        ]);
    }

    private function campanaActiva(): Campana
    {
        $campana = Campana::withoutGlobalScopes()
            ->where('distribuidora_id', $this->distribuidora()->id)
            ->where('estado', 'activa')
            ->orderBy('id')
            ->first();

        $this->assertNotNull($campana, 'El seeder demo no dej? campa?a activa.');

        return $campana;
    }

    private function marcaCualquiera(): Marca
    {
        $marca = Marca::withoutGlobalScopes()
            ->where('distribuidora_id', $this->distribuidora()->id)
            ->orderBy('id')
            ->first();

        $this->assertNotNull($marca, 'El seeder demo no dej? marcas.');

        return $marca;
    }

    public function test_al_llegar_al_cupo_no_se_puede_crear_otra_linea_activa(): void
    {
        $campana = $this->campanaActiva();
        $marca = $this->marcaCualquiera();
        $distribuidoraId = $this->distribuidora()->id;

        Linea::withoutGlobalScopes()
            ->where('distribuidora_id', $distribuidoraId)
            ->update(['activa' => false]);

        Linea::withoutGlobalScopes()->create([
            'distribuidora_id' => $distribuidoraId,
            'campana_id' => $campana->id,
            'nombre' => 'Linea cupo TG-158',
            'descripcion' => 'Ocupa el unico cupo',
            'activa' => true,
        ]);

        $this->asegurarSuscripcionConLimite(1);
        Tenant::olvidarCache();

        Livewire::test('catalogo.lineas')
            ->assertSet('cupoLineasAlcanzado', true)
            ->assertSet('lineasLimitePlan', 1)
            ->assertSet('lineasActivasCount', 1)
            ->call('abrirFormularioCrearLinea')
            ->set('linea_campana_id', $campana->id)
            ->set('linea_nombre', 'Linea que no debe caber')
            ->set('linea_marca_ids', [$marca->id])
            ->call('guardarLinea')
            ->assertNotSet('errorNegocio', null);

        $this->assertSame(
            1,
            (int) Linea::withoutGlobalScopes()
                ->where('distribuidora_id', $distribuidoraId)
                ->where('activa', true)
                ->count()
        );
        $this->assertFalse(
            Linea::withoutGlobalScopes()
                ->where('distribuidora_id', $distribuidoraId)
                ->where('nombre', 'Linea que no debe caber')
                ->exists()
        );
    }

    public function test_con_cupo_libre_se_puede_crear_una_linea_desde_livewire(): void
    {
        $campana = $this->campanaActiva();
        $marca = $this->marcaCualquiera();
        $distribuidoraId = $this->distribuidora()->id;

        Linea::withoutGlobalScopes()
            ->where('distribuidora_id', $distribuidoraId)
            ->update(['activa' => false]);

        $this->asegurarSuscripcionConLimite(2);
        Tenant::olvidarCache();

        Livewire::test('catalogo.lineas')
            ->assertSet('cupoLineasAlcanzado', false)
            ->call('abrirFormularioCrearLinea')
            ->set('linea_campana_id', $campana->id)
            ->set('linea_nombre', 'Linea cupo libre TG-158')
            ->set('linea_descripcion', 'Creada por test Livewire')
            ->set('linea_marca_ids', [$marca->id])
            ->call('guardarLinea')
            ->assertSet('errorNegocio', null)
            ->assertSet('mostrandoFormularioLinea', false);

        $this->assertTrue(
            Linea::withoutGlobalScopes()
                ->where('distribuidora_id', $distribuidoraId)
                ->where('nombre', 'Linea cupo libre TG-158')
                ->where('activa', true)
                ->exists()
        );
    }
}
