<?php

namespace Tests\Feature\Catalogo;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BorrarCatalogoDemoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_sin_confirmar_no_borra_nada(): void
    {
        $antes = DB::table('producto_campana')->count();
        $this->assertGreaterThan(0, $antes);

        $this->artisan('catalogo:borrar-demo')->assertSuccessful();

        $this->assertSame($antes, DB::table('producto_campana')->count());
    }

    public function test_con_confirmar_borra_la_demo_y_desactiva_lo_que_tiene_pedidos(): void
    {
        $lineas = DB::table('lineas')->count();

        $this->artisan('catalogo:borrar-demo --confirmar')->assertSuccessful();
        $this->artisan('catalogo:borrar-demo --confirmar')->assertSuccessful();

        $quedan = DB::table('producto_campana')->get();

        foreach ($quedan as $pc) {
            $this->assertFalse((bool) $pc->activo);
            $this->assertTrue(
                DB::table('pedido_detalle')->where('producto_campana_id', $pc->id)->exists()
                || DB::table('venta_directa_detalle')->where('producto_campana_id', $pc->id)->exists()
            );
        }

        $this->assertSame($lineas, DB::table('lineas')->count());
        $this->assertSame(0, DB::table('producto_imagenes')->whereNotIn('producto_campana_id', $quedan->pluck('id'))->count());
    }
}