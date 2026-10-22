<?php

namespace Tests\Feature\Cache;

use App\Models\Usuario;
use App\Support\ContextoOperativo;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** ContextoOperativo es scoped: se resuelve una vez por petición, pero nunca con el usuario anterior. */
class ContextoOperativoPorPeticionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function como(string $email): void
    {
        $this->app['auth']->forgetGuards();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        $this->actingAs(Usuario::where('email', $email)->firstOrFail());
        Tenant::olvidarCache();
    }

    public function test_la_misma_instancia_no_repite_consultas(): void
    {
        $this->como('admin@calzadosramirez.test');
        app(ContextoOperativo::class)->sucursalPrincipal();

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(ContextoOperativo::class)->staff();
        app(ContextoOperativo::class)->sucursalPrincipal();
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_si_cambia_el_usuario_se_vuelve_a_resolver(): void
    {
        $this->como('admin@calzadosramirez.test');
        $a = app(ContextoOperativo::class)->sucursalPrincipal();

        $this->como('admin@boutiquedelcalzado.test');
        $b = app(ContextoOperativo::class)->sucursalPrincipal();

        $this->assertNotSame($a->distribuidora_id, $b->distribuidora_id);
        $this->assertSame(Tenant::id(), (int) app(ContextoOperativo::class)->distribuidoraId());
    }
}
