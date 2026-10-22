<?php

namespace Tests\Feature\Catalogo;

use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AyudasFuncionesCompletas;
use Tests\TestCase;

/** El catálogo de la app responde 304 cuando no cambió. */
class CatalogoEtagTest extends TestCase
{
    use AyudasFuncionesCompletas;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    public function test_el_mismo_etag_no_vuelve_a_mandar_el_catalogo(): void
    {
        $this->comoApi(self::MARIA);

        $primero = $this->getJson('/api/catalogo')->assertOk();
        $this->assertNotEmpty($primero->json('data'));

        $etag = $primero->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $segundo = $this->withHeaders(['If-None-Match' => $etag])->get('/api/catalogo');
        $segundo->assertStatus(304);
        $this->assertSame('', $segundo->getContent());
    }

    public function test_la_pagina_del_enlace_existe_sin_iniciar_sesion(): void
    {
        $this->get('/abrir/pedido/15')->assertOk()->assertSee('Abrir el pedido')->assertSee('https://footwearpoint.app/abrir/pedido/15');
        $this->get('/abrir/producto/8')->assertOk()->assertSee('Abrir el producto');
        $this->get('/abrir/otro/1')->assertNotFound();
    }
}
