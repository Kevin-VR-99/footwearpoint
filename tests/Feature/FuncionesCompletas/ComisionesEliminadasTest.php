<?php

namespace Tests\Feature\FuncionesCompletas;

use App\Database\LimpiezaComisionesMayoristas;
use App\Models\Auditoria;
use App\Models\DistribuidoraStaff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Concerns\AyudasFuncionesCompletas;
use Tests\TestCase;

/**
 * S5-53 y S5-54: ya no hay comisiones ni metas de clientes mayoristas.
 * El filtro de auditoría es el de la migración 2026_11_12_120000.
 */
class ComisionesEliminadasTest extends TestCase
{
    use AyudasFuncionesCompletas;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_no_quedan_rutas_ni_columnas_ni_el_permiso(): void
    {
        $this->assertFalse(Schema::hasTable('metas_revendedor'));
        $this->assertFalse(Schema::hasColumn('configuraciones_distribuidora', 'comision_porcentaje_default'));
        $this->assertFalse(Schema::hasColumn('revendedor_distribuidora', 'comision_porcentaje'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('comisiones.index'));

        $this->comoPanel(self::ADMIN);
        $this->get('/comisiones')->assertNotFound();
        Livewire::test('distribuidora.usuarios')->assertDontSee('Ver comisiones y metas');

        $this->comoApi(self::MARIA);
        $this->getJson('/api/revendedor/comisiones')->assertNotFound();

        $this->comoApi(self::ADMIN);
        $this->getJson('/api/distribuidora/comisiones')->assertNotFound();
    }

    public function test_el_borrado_de_auditoria_solo_quita_esas_tres_acciones(): void
    {
        foreach (LimpiezaComisionesMayoristas::ACCIONES_AUDITORIA as $accion) {
            Auditoria::create([
                'accion' => $accion,
                'entidad_tipo' => 'configuracion_distribuidora',
                'entidad_id' => 1,
            ]);
        }

        Auditoria::create([
            'accion' => 'pago.saldo_pedido',
            'entidad_tipo' => 'pago',
            'entidad_id' => 1,
        ]);

        $borradas = LimpiezaComisionesMayoristas::borrarAuditoria();

        $this->assertSame(3, $borradas);
        $this->assertSame(0, Auditoria::whereIn('accion', LimpiezaComisionesMayoristas::ACCIONES_AUDITORIA)->count());
        $this->assertSame(1, Auditoria::where('accion', 'pago.saldo_pedido')->count());
    }

    public function test_se_quita_comisiones_ver_del_json_sin_tocar_los_demas_permisos(): void
    {
        $staff = DistribuidoraStaff::query()->whereNotNull('usuario_id')->firstOrFail();
        $staff->forceFill([
            'permisos_finos' => [
                'comisiones.ver' => true,
                'pagos.registrar' => false,
            ],
        ])->save();

        LimpiezaComisionesMayoristas::quitarPermisoFino();

        $guardados = $staff->fresh()->permisos_finos;
        $this->assertIsArray($guardados);
        $this->assertArrayNotHasKey('comisiones.ver', $guardados);
        $this->assertFalse($guardados['pagos.registrar']);
    }
}
