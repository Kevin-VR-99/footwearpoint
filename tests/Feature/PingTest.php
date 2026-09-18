<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * GET /api/ping — comprobar que el servidor responde (TG-162).
 *
 * Se había cambiado a POST sin motivo en E9-04. Esta prueba evita que vuelva
 * a pasar sin que nadie se entere.
 */
class PingTest extends TestCase
{
    public function test_el_ping_responde_por_get(): void
    {
        $this->getJson('/api/ping')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }

    public function test_el_ping_no_se_usa_por_post(): void
    {
        $this->postJson('/api/ping')->assertStatus(405);
    }
}
