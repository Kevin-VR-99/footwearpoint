<?php

namespace Tests;

use App\Services\Correo\VerificadorDominioCorreo;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Las pruebas no usan el access token real de FootwearPoint (MP_ACCESS_TOKEN)
        // que pueda venir del .env o del entorno: con él, el webhook pregunta
        // GET /users/me a Mercado Pago sin fake. Las pruebas que lo necesitan lo ponen.
        config(['services.mercadopago.access_token' => null]);

        // Ninguna prueba consulta el DNS real: todo dominio "existe" salvo
        // los .invalid. Una prueba puede registrar su propio falso.
        $this->app->instance(VerificadorDominioCorreo::class, VerificadorDominioCorreo::falso());
    }
}
