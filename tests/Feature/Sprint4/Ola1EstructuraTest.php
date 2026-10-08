<?php

namespace Tests\Feature\Sprint4;

use App\Models\ConfiguracionDistribuidora;
use App\Models\Distribuidora;
use App\Models\Pago;
use App\Models\PlanSuscripcion;
use App\Models\Suscripcion;
use App\Support\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * TG-208 — Ola 1 del Sprint 4: lo que se agregó a la base.
 *
 * No prueba funciones (todavía no hay), sino que la estructura quedó como
 * dice la sección 3 del diseño y que se puede usar: así, si alguien tumba una
 * columna o una llave sin querer, se nota aquí y no hasta que Gabriel o
 * Ailton lo ocupen.
 */
class Ola1EstructuraTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_las_columnas_nuevas_existen(): void
    {
        $esperado = [
            'configuraciones_distribuidora' => ['mp_access_token', 'mp_public_key', 'mp_conectado_at', 'descuento_mayorista_pct'],
            'pagos' => ['suscripcion_id', 'preferencia_externa'],
            'distribuidoras' => ['motivo_rechazo'],
            'usuarios' => ['debe_cambiar_password'],
            'vales' => ['aviso_vencimiento_enviado_at'],
            'importaciones_catalogo' => ['linea_id', 'campana_id', 'iniciada_por_usuario_id', 'revisada_por_usuario_id', 'modelo_ia', 'paginas', 'tokens_entrada', 'tokens_salida', 'costo_usd'],
            'productos_importados_staging' => ['producto_campana_creado_id'],
        ];

        foreach ($esperado as $tabla => $columnas) {
            foreach ($columnas as $columna) {
                $this->assertTrue(Schema::hasColumn($tabla, $columna), "Falta $tabla.$columna");
            }
        }

        foreach (['webhooks_mercado_pago', 'categorias_directorio', 'distribuidora_categoria_directorio'] as $tabla) {
            $this->assertTrue(Schema::hasTable($tabla), "Falta la tabla $tabla");
        }
    }

    /** El catálogo pasa a ser del admin general: ya no es de una distribuidora. */
    public function test_las_importaciones_de_catalogo_ya_no_son_de_una_distribuidora(): void
    {
        $this->assertFalse(Schema::hasColumn('importaciones_catalogo', 'distribuidora_id'));
        $this->assertFalse(Schema::hasColumn('importaciones_catalogo', 'iniciada_por_staff_id'));
        $this->assertFalse(Schema::hasColumn('productos_importados_staging', 'distribuidora_id'));
    }

    public function test_se_puede_registrar_un_pago_de_suscripcion(): void
    {
        $distribuidora = Distribuidora::firstOrFail();
        Tenant::olvidarCache();

        $suscripcion = Suscripcion::create([
            'distribuidora_id' => $distribuidora->id,
            'plan_id' => PlanSuscripcion::firstOrFail()->id,
            'fecha_inicio' => now()->toDateString(),
            'estado' => 'activa',
            'precio_base_contratado' => 1200,
            'lineas_incluidas_contratadas' => 2,
            'precio_linea_extra_contratado' => 300,
        ]);

        $pago = Pago::create([
            'distribuidora_id' => $distribuidora->id,
            'suscripcion_id' => $suscripcion->id,
            'folio' => 'PAG-SUS-0001',
            'tipo' => 'suscripcion',
            'direccion' => 'entrada',
            'metodo' => 'mercado_pago',
            'monto' => 1200,
            'estado' => 'pendiente',
            'proveedor_pago' => 'mercado_pago',
            'preferencia_externa' => 'pref-123',
        ]);

        $this->assertSame($suscripcion->id, $pago->fresh()->suscripcion->id);
    }

    /** El token cobra dinero: en la base no debe quedar legible. */
    public function test_el_token_de_mercado_pago_se_guarda_cifrado(): void
    {
        $config = ConfiguracionDistribuidora::firstOrFail();
        $config->update(['mp_access_token' => 'APP_USR-token-de-prueba']);

        $enLaBase = DB::table('configuraciones_distribuidora')->where('id', $config->id)->value('mp_access_token');

        $this->assertNotSame('APP_USR-token-de-prueba', $enLaBase);
        $this->assertStringNotContainsString('APP_USR', (string) $enLaBase);
        $this->assertSame('APP_USR-token-de-prueba', $config->fresh()->mp_access_token);
    }

    /** Mercado Pago manda el mismo aviso varias veces: no se procesa dos veces. */
    public function test_un_aviso_repetido_de_mercado_pago_no_se_guarda_dos_veces(): void
    {
        $aviso = [
            'tipo' => 'payment',
            'recurso_id' => '1234567890',
            'payload' => json_encode(['id' => '1234567890']),
            'created_at' => now(),
        ];

        DB::table('webhooks_mercado_pago')->insert($aviso);

        $this->expectException(QueryException::class);
        DB::table('webhooks_mercado_pago')->insert($aviso);
    }

    public function test_los_permisos_por_modulo_quedan_creados(): void
    {
        foreach (\Database\Seeders\RolesPermissionsSeeder::PERMISOS_POR_MODULO as $permiso) {
            $this->assertTrue(
                Permission::where('name', $permiso)->where('guard_name', 'web')->exists(),
                "Falta el permiso $permiso"
            );
        }
    }
}
