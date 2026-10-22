<?php

namespace Tests\Feature\FuncionesCompletas;

use App\Services\Notificacion\Push\EnviadorPush;
use App\Services\Notificacion\Push\EnviadorPushFirebase;
use App\Services\Notificacion\Push\EnviadorPushNulo;
use App\Support\CredencialesFirebase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/** Push: Firebase solo con credenciales; sin ellas, un aviso claro en el log. */
class EnviadorPushTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        EnviadorPushNulo::reiniciar();
        config([CredencialesFirebase::clave() => null, 'services.firebase.credenciales_base64' => null]);
    }

    public function test_sin_credenciales_usa_el_nulo_y_lo_dice_una_vez_en_el_log(): void
    {
        Log::spy();
        $enviador = app(EnviadorPush::class);
        $this->assertInstanceOf(EnviadorPushNulo::class, $enviador);

        $this->assertSame([], $enviador->enviar(['t1'], 'Hola', 'Mensaje'));
        $enviador->enviar(['t2'], 'Hola', 'Mensaje');

        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'Push desactivado'))->once();
    }

    public function test_con_credenciales_en_json_usa_firebase(): void
    {
        config([CredencialesFirebase::clave() => '{"type":"service_account"}']);
        $this->assertInstanceOf(EnviadorPushFirebase::class, app(EnviadorPush::class));
    }

    public function test_con_credenciales_en_base64_las_pasa_a_kreait(): void
    {
        // Datos de juguete: no es una llave real.
        $json = json_encode(['type' => 'service_account', 'project_id' => 'demo', 'client_email' => 'demo@demo.iam', 'private_key' => 'no-es-una-llave']);
        config(['services.firebase.credenciales_base64' => base64_encode($json)]);

        $this->assertInstanceOf(EnviadorPushFirebase::class, app(EnviadorPush::class));
        $this->assertSame('demo', config(CredencialesFirebase::clave())['project_id']);
    }

    public function test_una_ruta_que_no_existe_no_cuenta_como_credencial(): void
    {
        config([CredencialesFirebase::clave() => 'storage/app/firebase/no-existe.json']);
        $this->assertInstanceOf(EnviadorPushNulo::class, app(EnviadorPush::class));
    }
}
