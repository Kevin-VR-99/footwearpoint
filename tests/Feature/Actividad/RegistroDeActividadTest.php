<?php

namespace Tests\Feature\Actividad;

use App\Console\Commands\LimpiarActividad;
use App\Models\ActividadDiaria;
use App\Models\ActividadMensual;
use App\Models\Auditoria;
use App\Models\Usuario;
use App\Services\Actividad\ConsolidarActividad;
use App\Services\Actividad\RegistrarActividad;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TG-406 (A43) — Registro de actividad.
 *
 * El «listo cuando» de la tarjeta es literal: cada regla con su prueba. Las
 * reglas son nueve y van en ese orden abajo.
 */
class RegistroDeActividadTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function registro(): RegistrarActividad
    {
        return app(RegistrarActividad::class);
    }

    private function alguien(int $cual = 0): Usuario
    {
        return Usuario::query()->orderBy('id')->skip($cual)->firstOrFail();
    }

    private function marcarEn(Usuario $u, string $fecha, string $canal): void
    {
        DB::table('actividad_diaria')->insertOrIgnore([
            'usuario_id' => $u->id, 'fecha' => $fecha, 'canal' => $canal,
        ]);
    }

    // --- 1. Una fila por persona, día y canal -------------------------------

    public function test_marcar_dos_veces_el_mismo_dia_deja_una_sola_fila(): void
    {
        $u = $this->alguien();

        $this->assertTrue($this->registro()->marcar($u->id, ActividadDiaria::CANAL_PANEL));
        $this->assertFalse($this->registro()->marcar($u->id, ActividadDiaria::CANAL_PANEL));

        $this->assertSame(1, ActividadDiaria::where('usuario_id', $u->id)->count());
    }

    public function test_el_mismo_dia_en_dos_canales_son_dos_filas(): void
    {
        $u = $this->alguien();

        $this->registro()->marcar($u->id, ActividadDiaria::CANAL_PANEL);
        $this->registro()->marcar($u->id, ActividadDiaria::CANAL_APP);

        $this->assertSame(2, ActividadDiaria::where('usuario_id', $u->id)->count());
    }

    public function test_un_canal_inventado_no_se_guarda(): void
    {
        $u = $this->alguien();

        $this->assertFalse($this->registro()->marcar($u->id, 'whatsapp'));
        $this->assertSame(0, ActividadDiaria::count());
    }

    // --- 2. Sin IP ni acciones ---------------------------------------------

    /**
     * La tabla solo tiene las tres dimensiones.
     *
     * Es la garantía de la política: con estas columnas se puede responder
     * «cuánta gente distinta entró», y no se puede responder «qué hizo fulano».
     */
    public function test_la_tabla_no_guarda_ip_ni_acciones(): void
    {
        $columnas = \Illuminate\Support\Facades\Schema::getColumnListing('actividad_diaria');

        $this->assertEqualsCanonicalizing(['id', 'usuario_id', 'fecha', 'canal'], $columnas);
    }

    // --- 3 y 4. 90 días de detalle, 24 meses de totales ---------------------

    public function test_el_detalle_de_mas_de_90_dias_se_borra(): void
    {
        $u = $this->alguien();
        $hoy = now(ActividadDiaria::ZONA);

        $this->marcarEn($u, $hoy->copy()->subDays(89)->toDateString(), 'panel');
        $this->marcarEn($u, $hoy->copy()->subDays(91)->toDateString(), 'panel');

        app(ConsolidarActividad::class)->ejecutar();

        $this->assertSame(1, ActividadDiaria::count(), 'Se borró lo que no tocaba, o no se borró lo vencido.');
        $this->assertTrue(ActividadDiaria::first()->fecha->greaterThan($hoy->copy()->subDays(90)));
    }

    public function test_los_totales_de_mas_de_24_meses_se_borran(): void
    {
        $viejo = now(ActividadDiaria::ZONA)->subMonthsNoOverflow(25)->format('Y-m');
        $reciente = now(ActividadDiaria::ZONA)->subMonthsNoOverflow(23)->format('Y-m');

        foreach ([$viejo, $reciente] as $mes) {
            ActividadMensual::create(['anio_mes' => $mes, 'canal' => 'total', 'usuarios_unicos' => 7]);
        }

        app(ConsolidarActividad::class)->ejecutar();

        $this->assertNull(ActividadMensual::where('anio_mes', $viejo)->first());
        $this->assertNotNull(ActividadMensual::where('anio_mes', $reciente)->first());
    }

    /** Primero consolidar, después borrar: al revés se pierde el único dato. */
    public function test_el_mes_queda_contado_antes_de_que_se_borre_su_detalle(): void
    {
        $u = $this->alguien();
        $hace91 = now(ActividadDiaria::ZONA)->subDays(91);

        $this->marcarEn($u, $hace91->toDateString(), 'panel');

        app(ConsolidarActividad::class)->ejecutar();

        $total = ActividadMensual::where('anio_mes', $hace91->format('Y-m'))->where('canal', 'total')->first();

        $this->assertNotNull($total, 'Se borró el detalle sin haber contado el mes.');
        $this->assertSame(1, $total->usuarios_unicos);
        $this->assertSame(0, ActividadDiaria::count());
    }

    // --- 5. Únicos entre panel y app ---------------------------------------

    /**
     * Quien entra por los dos lados cuenta UNA vez en el total.
     *
     * Por eso el total se guarda aparte y no se suma panel + app al leer:
     * sumarlos daría 2 donde hay 1 persona.
     */
    public function test_quien_usa_panel_y_app_cuenta_una_sola_vez(): void
    {
        $u = $this->alguien();
        $hoy = now(ActividadDiaria::ZONA)->toDateString();
        $mes = now(ActividadDiaria::ZONA)->format('Y-m');

        $this->marcarEn($u, $hoy, 'panel');
        $this->marcarEn($u, $hoy, 'app');

        app(ConsolidarActividad::class)->ejecutar();

        $por = ActividadMensual::where('anio_mes', $mes)->pluck('usuarios_unicos', 'canal');

        $this->assertSame(1, $por['panel']);
        $this->assertSame(1, $por['app']);
        $this->assertSame(1, $por['total'], 'El total sumó los canales en vez de contar personas distintas.');
    }

    public function test_dos_personas_distintas_si_cuentan_dos(): void
    {
        $hoy = now(ActividadDiaria::ZONA)->toDateString();

        $this->marcarEn($this->alguien(0), $hoy, 'panel');
        $this->marcarEn($this->alguien(1), $hoy, 'app');

        app(ConsolidarActividad::class)->ejecutar();

        $mes = now(ActividadDiaria::ZONA)->format('Y-m');

        $this->assertSame(2, ActividadMensual::where('anio_mes', $mes)->where('canal', 'total')->value('usuarios_unicos'));
    }

    // --- 6. Grupos menores a 5 ---------------------------------------------

    public function test_los_grupos_menores_a_cinco_no_enseñan_el_numero(): void
    {
        $this->assertSame('menos de 5', ActividadMensual::comoSeEnseña(1));
        $this->assertSame('menos de 5', ActividadMensual::comoSeEnseña(4));
        $this->assertSame('5', ActividadMensual::comoSeEnseña(5));
        $this->assertSame('42', ActividadMensual::comoSeEnseña(42));
    }

    /** Cero es cero: no hay a quién señalar, y «menos de 5» confundiría. */
    public function test_el_cero_se_enseña_como_cero(): void
    {
        $this->assertSame('0', ActividadMensual::comoSeEnseña(0));
    }

    // --- 7. Por temporada, el promedio mensual ------------------------------

    /**
     * Una temporada son seis meses. Sumar sus totales contaría varias veces a
     * quien entró en varios meses; el promedio no.
     */
    public function test_la_temporada_usa_el_promedio_mensual_y_no_la_suma(): void
    {
        $base = Carbon::create(2026, 1, 1, 0, 0, 0, ActividadDiaria::ZONA);

        foreach ([10, 20, 30] as $i => $cuantos) {
            ActividadMensual::create([
                'anio_mes' => $base->copy()->addMonthsNoOverflow($i)->format('Y-m'),
                'canal' => 'total',
                'usuarios_unicos' => $cuantos,
            ]);
        }

        $promedio = ActividadMensual::promedioMensual($base, $base->copy()->addMonthsNoOverflow(2));

        $this->assertSame(20.0, $promedio, 'Debe ser el promedio (20), no la suma (60).');
    }

    public function test_una_temporada_sin_datos_no_inventa_un_cero(): void
    {
        $base = Carbon::create(2020, 1, 1, 0, 0, 0, ActividadDiaria::ZONA);

        $this->assertNull(ActividadMensual::promedioMensual($base, $base->copy()->addMonthsNoOverflow(5)));
    }

    // --- 8. La limpieza -----------------------------------------------------

    public function test_la_limpieza_se_puede_repetir_sin_duplicar(): void
    {
        $this->marcarEn($this->alguien(), now(ActividadDiaria::ZONA)->toDateString(), 'panel');

        $this->artisan('actividad:limpiar')->assertSuccessful();
        $primera = ActividadMensual::count();

        $this->artisan('actividad:limpiar')->assertSuccessful();

        $this->assertSame($primera, ActividadMensual::count(), 'La segunda corrida duplicó los totales.');
    }

    public function test_la_limpieza_queda_en_auditoria(): void
    {
        $this->artisan('actividad:limpiar')->assertSuccessful();

        $this->assertTrue(
            Auditoria::withoutGlobalScopes()->where('accion', LimpiarActividad::ACCION_AUDITORIA)->exists(),
            'Sin el registro en auditoría, Métricas no puede saber cuándo fue la última limpieza.'
        );
    }

    /** Si quedó detalle vencido, algo no corrió: Métricas lo tiene que poder ver. */
    public function test_se_puede_saber_si_quedo_detalle_vencido(): void
    {
        $consolidar = app(ConsolidarActividad::class);

        $this->assertFalse($consolidar->hayDetalleVencido());

        $this->marcarEn($this->alguien(), now(ActividadDiaria::ZONA)->subDays(120)->toDateString(), 'panel');

        $this->assertTrue($consolidar->hayDetalleVencido());

        $consolidar->ejecutar();

        $this->assertFalse($consolidar->hayDetalleVencido());
    }

    public function test_si_la_limpieza_falla_avisa_por_correo_y_no_toca_la_auditoria(): void
    {
        // Sin Mail::fake: el transporte 'array' de las pruebas guarda los
        // mensajes de verdad, y Mail::raw no lo registra el fake.
        config()->set('footwearpoint.redes.correo', 'avisos@ejemplo.test');
        $buzon = \Illuminate\Support\Facades\Mail::mailer()->getSymfonyTransport();

        $this->mock(ConsolidarActividad::class, function ($simulado) {
            $simulado->shouldReceive('ejecutar')->andThrow(new \RuntimeException('la base se cayo'));
        });

        $this->artisan('actividad:limpiar')->assertFailed();

        $mensajes = $buzon->messages();
        $this->assertCount(1, $mensajes, 'No salió el aviso de que la limpieza falló.');
        $this->assertStringContainsString('avisos@ejemplo.test', $mensajes[0]->getEnvelope()->getRecipients()[0]->getAddress());

        // Y NO queda en auditoria: ese registro es justo lo que Metricas mira
        // para saber cuando fue la ultima limpieza BUENA. Apuntar una fallida
        // haria creer que todo esta al dia.
        $this->assertFalse(
            Auditoria::withoutGlobalScopes()->where('accion', LimpiarActividad::ACCION_AUDITORIA)->exists(),
            'Una limpieza fallida quedó registrada como si hubiera funcionado.'
        );
    }

    /** La alerta que Métricas enseña cuando el cron dejó de correr. */
    public function test_avisa_si_la_limpieza_nunca_ha_corrido(): void
    {
        $this->assertStringContainsString('todavía no ha corrido', app(ConsolidarActividad::class)->motivoDeAlerta());
    }

    public function test_no_avisa_si_acaba_de_correr(): void
    {
        $this->artisan('actividad:limpiar')->assertSuccessful();

        $this->assertNull(app(ConsolidarActividad::class)->motivoDeAlerta());
    }

    public function test_avisa_si_la_ultima_limpieza_tiene_mas_de_dos_dias(): void
    {
        $this->artisan('actividad:limpiar')->assertSuccessful();

        Auditoria::withoutGlobalScopes()
            ->where('accion', LimpiarActividad::ACCION_AUDITORIA)
            ->update(['created_at' => now()->subDays(3)]);

        $motivo = app(ConsolidarActividad::class)->motivoDeAlerta();

        $this->assertNotNull($motivo, 'Pasaron 3 días sin limpieza y nadie avisó.');
        $this->assertStringContainsString('última limpieza', $motivo);
    }

    public function test_avisa_si_quedo_detalle_vencido_aunque_la_limpieza_sea_reciente(): void
    {
        $this->artisan('actividad:limpiar')->assertSuccessful();

        // Detalle vencido metido DESPUÉS de la limpieza: la fecha de la última
        // corrida se ve bien, pero los datos viejos siguen ahí.
        $this->marcarEn($this->alguien(), now(ActividadDiaria::ZONA)->subDays(120)->toDateString(), 'panel');

        $this->assertStringContainsString(
            'sin borrar',
            app(ConsolidarActividad::class)->motivoDeAlerta()
        );
    }

    // --- 9. Al borrar a una persona se borran sus filas ---------------------

    public function test_al_borrar_a_una_persona_se_van_sus_filas(): void
    {
        $u = Usuario::create([
            'nombre' => 'Prueba A43',
            'email' => 'a43-'.uniqid().'@ejemplo.test',
            'password' => bcrypt('password-de-prueba'),
        ]);

        $this->marcarEn($u, now(ActividadDiaria::ZONA)->toDateString(), 'panel');
        $this->assertSame(1, ActividadDiaria::where('usuario_id', $u->id)->count());

        $u->forceDelete();

        $this->assertSame(
            0,
            ActividadDiaria::where('usuario_id', $u->id)->count(),
            'Quedaron filas de alguien que ya se borró.'
        );
    }
}
