<?php

namespace App\Services\Distribuidora;

use App\Exceptions\OperacionInvalidaException;
use App\Models\ClienteDirecto;
use App\Models\DistribuidoraStaff;
use App\Models\RevendedorDistribuidora;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Dar de baja y reactivar el acceso de clientes mayoristas, clientes directos
 * y empleados.
 *
 * La baja NO borra nada: pedidos, pagos, vales y bitácora se quedan. Solo
 * cambia el estado a 'inactivo' (con lo que Tenant ya no resuelve su
 * distribuidora) y revoca sus tokens de la app (RevocarAccesoUsuarioAction).
 * El login de la app lo rechaza con un mensaje claro (AuthController).
 */
class BajaAccesoAction
{
    public const TIPOS = ['revendedor', 'cliente', 'empleado'];

    public function __construct(
        private RegistrarAuditoriaAction $auditoria,
        private RevocarAccesoUsuarioAction $revocar,
    ) {
    }

    public function darDeBaja(string $tipo, int $id, ?string $motivo = null): Model
    {
        return $this->cambiar($tipo, $id, 'inactivo', $motivo);
    }

    public function reactivar(string $tipo, int $id): Model
    {
        return $this->cambiar($tipo, $id, 'activo', null);
    }

    private function cambiar(string $tipo, int $id, string $estado, ?string $motivo): Model
    {
        return DB::transaction(function () use ($tipo, $id, $estado, $motivo) {
            $registro = $this->buscar($tipo, $id);
            $antes = (string) $registro->estado;

            if ($antes === $estado) {
                return $registro;
            }

            $usuarioId = $this->usuarioDe($tipo, $registro);

            if ($tipo === 'empleado' && $estado === 'inactivo' && (int) $usuarioId === (int) Auth::id()) {
                throw new OperacionInvalidaException('No puedes darte de baja a ti mismo.', 422);
            }

            // El modelo revoca al guardar (RevocaAccesoAlDesactivar); se cuentan
            // antes para dejarlos en la bitácora.
            $tokensAntes = $usuarioId ? \Laravel\Sanctum\PersonalAccessToken::query()
                ->where('tokenable_type', (new \App\Models\Usuario)->getMorphClass())
                ->where('tokenable_id', $usuarioId)->count() : 0;

            $registro->estado = $estado;
            $registro->save();

            $tokens = 0;
            if ($estado !== 'activo' && $usuarioId) {
                $tokens = $tokensAntes - \Laravel\Sanctum\PersonalAccessToken::query()
                    ->where('tokenable_type', (new \App\Models\Usuario)->getMorphClass())
                    ->where('tokenable_id', $usuarioId)->count();
            }

            $this->auditoria->ejecutar(
                $estado === 'activo' ? 'acceso.reactivado' : 'acceso.baja',
                $this->entidad($tipo),
                (int) $registro->id,
                ['estado' => $antes],
                array_filter([
                    'estado'           => $estado,
                    'motivo'           => $motivo !== null && trim($motivo) !== '' ? mb_substr(trim($motivo), 0, 300) : null,
                    'tokens_revocados' => $estado === 'activo' ? null : $tokens,
                ], fn ($v) => $v !== null),
            );

            return $registro->fresh();
        });
    }

    private function buscar(string $tipo, int $id): Model
    {
        // Con el scope del tenant: lo de otra distribuidora no existe.
        $registro = match ($tipo) {
            'revendedor' => RevendedorDistribuidora::query()->with('revendedor')->find($id),
            'cliente'    => ClienteDirecto::query()->find($id),
            'empleado'   => DistribuidoraStaff::query()->where('tipo', 'empleado')->find($id),
            default      => throw new OperacionInvalidaException('Tipo de acceso no válido.', 422),
        };

        if ($registro === null) {
            throw new OperacionInvalidaException('No se encontró a esa persona en tu distribuidora.', 404);
        }

        return $registro;
    }

    private function usuarioDe(string $tipo, Model $registro): ?int
    {
        $id = $tipo === 'revendedor' ? $registro->revendedor?->usuario_id : $registro->usuario_id;

        return $id !== null ? (int) $id : null;
    }

    private function entidad(string $tipo): string
    {
        return match ($tipo) {
            'revendedor' => 'revendedor_distribuidora',
            'cliente'    => 'cliente_directo',
            default      => 'distribuidora_staff',
        };
    }
}
