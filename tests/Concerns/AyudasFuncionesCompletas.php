<?php

namespace Tests\Concerns;

use App\Models\DisponibilidadVarianteCampana;
use App\Models\Distribuidora;
use App\Models\DistribuidoraStaff;
use App\Models\Revendedor;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Laravel\Sanctum\Sanctum;

/** Ayudas compartidas de las pruebas de feature/funciones-completas (datos demo). */
trait AyudasFuncionesCompletas
{
    protected const ADMIN = 'admin@calzadosramirez.test';
    protected const EMPLEADO = 'empleado@calzadosramirez.test';
    protected const OTRA_ADMIN = 'admin@boutiquedelcalzado.test';
    protected const MARIA = 'maria.lopez@revendedor.test';
    protected const JOSE = 'jose.hernandez@cliente.test';

    protected function usuario(string $email): Usuario
    {
        return Usuario::where('email', $email)->firstOrFail();
    }

    protected function comoPanel(string $email): Usuario
    {
        $this->app['auth']->forgetGuards();
        $usuario = $this->usuario($email);
        $this->actingAs($usuario);
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        return $usuario;
    }

    protected function comoApi(string $email): Usuario
    {
        $this->app['auth']->forgetGuards();
        $usuario = $this->usuario($email);
        Sanctum::actingAs($usuario);
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        return $usuario;
    }

    protected function ramirez(): Distribuidora
    {
        return Distribuidora::where('slug', 'calzados-ramirez')->firstOrFail();
    }

    protected function boutique(): Distribuidora
    {
        return Distribuidora::where('slug', 'boutique-del-calzado')->firstOrFail();
    }

    protected function staffDe(string $email): DistribuidoraStaff
    {
        return DistribuidoraStaff::withoutGlobalScopes()->where('usuario_id', $this->usuario($email)->id)->firstOrFail();
    }

    protected function variantePedible(): DisponibilidadVarianteCampana
    {
        return DisponibilidadVarianteCampana::withoutGlobalScopes()
            ->where('estado', 'disponible')
            ->whereHas('productoCampana', fn ($q) => $q->withoutGlobalScopes()
                ->where('activo', true)
                ->whereHas('campana', fn ($c) => $c->withoutGlobalScopes()->where('estado', 'activa')))
            ->orderBy('id')
            ->firstOrFail();
    }

    /** Un pedido enviado de María (cliente mayorista demo). */
    protected function pedidoEnviadoDeMaria(int $cantidad = 2): int
    {
        $maria = $this->comoApi(self::MARIA);

        $pedidoId = (int) $this->postJson('/api/pedidos', [
            'tipo' => 'revendedor',
            'propietario_id' => Revendedor::where('usuario_id', $maria->id)->value('id'),
            'sucursal_id' => Sucursal::withoutGlobalScopes()
                ->where('es_principal', true)
                ->value('id'),
        ])->assertCreated()->json('data.id');

        $variante = $this->variantePedible();

        $this->postJson("/api/pedidos/{$pedidoId}/lineas", [
            'producto_campana_id' => $variante->producto_campana_id,
            'variante_id' => $variante->variante_id,
            'cantidad' => $cantidad,
        ])->assertCreated();

        $this->postJson("/api/pedidos/{$pedidoId}/enviar")->assertOk();

        return $pedidoId;
    }
}
