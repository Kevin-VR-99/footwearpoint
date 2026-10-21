<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Api\AuthController;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/** Auditoría 21-oct-2026: el token lleva el nombre del dispositivo, hay tope por persona y se limpian los vencidos. */
class TokensPorDispositivoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const MARIA = 'maria.lopez@revendedor.test';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function tokensDeMaria()
    {
        return PersonalAccessToken::query()->where('tokenable_id', Usuario::where('email', self::MARIA)->value('id'))->orderBy('id');
    }

    public function test_el_token_se_llama_como_el_dispositivo(): void
    {
        $this->postJson('/api/auth/login', ['email' => self::MARIA, 'password' => 'password', 'device_name' => 'App FootwearPoint (android)'])->assertOk();
        $this->withHeader('User-Agent', 'Dart/3.13 (dart:io)')->postJson('/api/auth/login', ['email' => self::MARIA, 'password' => 'password'])->assertOk();

        $this->assertSame(['App FootwearPoint (android)', 'Dart/3.13 (dart:io)'], $this->tokensDeMaria()->pluck('name')->all());
    }

    public function test_solo_se_conservan_los_ultimos_tokens_de_cada_persona(): void
    {
        for ($i = 1; $i <= AuthController::MAX_TOKENS_POR_USUARIO + 3; $i++) {
            $this->postJson('/api/auth/login', ['email' => self::MARIA, 'password' => 'password', 'device_name' => "Tel {$i}"])->assertOk();
        }

        $nombres = $this->tokensDeMaria()->pluck('name');
        $this->assertCount(AuthController::MAX_TOKENS_POR_USUARIO, $nombres);
        $this->assertSame('Tel 4', $nombres->first());
        $this->assertSame('Tel '.(AuthController::MAX_TOKENS_POR_USUARIO + 3), $nombres->last());
    }

    public function test_sanctum_prune_expired_borra_los_vencidos(): void
    {
        $this->postJson('/api/auth/login', ['email' => self::MARIA, 'password' => 'password'])->assertOk();
        $this->postJson('/api/auth/login', ['email' => self::MARIA, 'password' => 'password'])->assertOk();
        $this->tokensDeMaria()->first()->forceFill(['created_at' => now()->subMinutes(config('sanctum.expiration') + 60 * 25)])->save();

        Artisan::call('sanctum:prune-expired', ['--hours' => 24]);

        $this->assertSame(1, $this->tokensDeMaria()->count());
    }
}
