<?php

namespace Tests\Feature\Seguridad;

use App\Http\Middleware\AgregarHsts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TG-235 (G17, E17-04) — Todo el tráfico usa HTTPS: HSTS en las respuestas
 * https y la cookie de sesión segura por omisión cuando APP_URL es https.
 */
class HttpsTest extends TestCase
{
    use RefreshDatabase;

    public function test_las_respuestas_https_traen_hsts(): void
    {
        $this->get('https://localhost/login')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', AgregarHsts::VALOR);

        $this->getJson('https://localhost/api/marketplace')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', AgregarHsts::VALOR);
    }

    public function test_las_respuestas_http_no_traen_hsts(): void
    {
        $this->get('http://localhost/login')
            ->assertOk()
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_la_cookie_de_sesion_es_segura_por_omision_si_app_url_es_https(): void
    {
        $this->assertTrue($this->secureCon('https://footwearpoint-production.up.railway.app', null));
        $this->assertFalse($this->secureCon('http://localhost', null));
        // SESSION_SECURE_COOKIE sigue mandando.
        $this->assertFalse($this->secureCon('https://footwearpoint-production.up.railway.app', 'false'));
        $this->assertTrue($this->secureCon('http://localhost', 'true'));
    }

    /** Valor de session.secure leyendo config/session.php con esas variables. */
    private function secureCon(string $appUrl, ?string $secureCookie): mixed
    {
        $antes = [
            'APP_URL'               => [$_ENV['APP_URL'] ?? null, $_SERVER['APP_URL'] ?? null, getenv('APP_URL')],
            'SESSION_SECURE_COOKIE' => [$_ENV['SESSION_SECURE_COOKIE'] ?? null, $_SERVER['SESSION_SECURE_COOKIE'] ?? null, getenv('SESSION_SECURE_COOKIE')],
        ];

        try {
            $this->poner('APP_URL', $appUrl);
            $this->poner('SESSION_SECURE_COOKIE', $secureCookie);

            return (require config_path('session.php'))['secure'];
        } finally {
            foreach ($antes as $nombre => [$env, $server, $put]) {
                $this->poner($nombre, $env ?? $server ?? ($put === false ? null : $put));
            }
        }
    }

    private function poner(string $nombre, ?string $valor): void
    {
        if ($valor === null) {
            unset($_ENV[$nombre], $_SERVER[$nombre]);
            putenv($nombre);

            return;
        }

        $_ENV[$nombre] = $_SERVER[$nombre] = $valor;
        putenv("{$nombre}={$valor}");
    }
}
