<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * La prueba de ejemplo que trae Laravel, puesta al día (TG-213).
 *
 * Esperaba un 200 en "/", pero FootwearPoint no tiene página pública ahí:
 * manda al login. Llevaba meses en rojo por eso y estorbaba para ver las
 * fallas de verdad.
 */
class ExampleTest extends TestCase
{
    public function test_la_raiz_del_sitio_manda_al_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }
}
