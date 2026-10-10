<?php

namespace Tests\Feature\Sprint4;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TG-276 — La cola en base de datos existe y sirve.
 *
 * Faltaba la migración de las tablas de la cola, así que con
 * QUEUE_CONNECTION=database no se podía encolar nada. Lo necesita el
 * procesamiento de catálogos con IA (A8).
 */
class ColaDeTrabajosTest extends TestCase
{
    use RefreshDatabase;

    public function test_las_tres_tablas_de_la_cola_existen(): void
    {
        $this->assertTrue(Schema::hasTable('jobs'));
        $this->assertTrue(Schema::hasTable('job_batches'));
        $this->assertTrue(Schema::hasTable('failed_jobs'));

        $this->assertTrue(Schema::hasColumns('jobs', [
            'id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at',
        ]));

        $this->assertTrue(Schema::hasColumns('failed_jobs', [
            'id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at',
        ]));
    }

    public function test_un_trabajo_encolado_se_guarda_en_la_tabla_jobs(): void
    {
        dispatch(function () {
            // No hace nada: aquí solo importa que quede guardado en la cola.
        })->onConnection('database')->onQueue('catalogos');

        $this->assertSame(1, DB::table('jobs')->where('queue', 'catalogos')->count());
    }

    public function test_un_lote_de_trabajos_se_guarda_en_job_batches(): void
    {
        $lote = Bus::batch([
            function () {
                // Igual que arriba: solo se revisa que el lote quede registrado.
            },
        ])->onConnection('database')->dispatch();

        $this->assertSame(1, DB::table('job_batches')->where('id', $lote->id)->count());
    }

    public function test_las_pruebas_siguen_corriendo_los_trabajos_de_inmediato(): void
    {
        // phpunit.xml deja QUEUE_CONNECTION=sync a propósito: lo que ya
        // funcionaba no se vuelve asíncrono por este cambio.
        $this->assertSame('sync', config('queue.default'));
    }
}
