<?php

namespace App\Services\Distribuidora;

use App\Exceptions\OperacionInvalidaException;
use App\Models\ClienteDirecto;
use App\Models\DistribuidoraStaff;
use App\Models\Revendedor;
use App\Models\RevendedorDistribuidora;
use App\Models\Usuario;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Services\Perfil\FotoPerfil;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * «Eliminar definitivamente» un empleado, un cliente mayorista o un cliente directo.
 *
 * Mismo criterio que el borrado de distribuidoras (PR #256): nunca se borra
 * historial ajeno. Si la persona ya tiene pedidos, vales, cambios, ventas o
 * cualquier otro movimiento, se bloquea con un mensaje claro y se sugiere
 * «Dar de baja», que conserva todo. Las llaves foráneas son la última red:
 * si algo que no se revisó aquí la referencia, MySQL lo impide (1451) y se
 * convierte en el mismo mensaje en vez de un error 500.
 *
 * La cuenta de acceso (usuarios) solo se borra si ya no la usa nadie más:
 * otra distribuidora, un cliente directo, etc. Si queda en uso, se le quitan
 * el rol y los tokens de esta distribuidora y se deja.
 */
class EliminarPersonaAction
{
    public const MENSAJE_EN_USO = 'tiene movimientos registrados (pedidos, pagos, ventas u otros) y no se puede eliminar sin perder historial. Dale de baja: ya no podrá entrar y su historial se conserva.';

    public function __construct(
        private RegistrarAuditoriaAction $auditoria,
        private FotoPerfil $fotos,
    ) {
    }

    public function empleado(int $staffId): void
    {
        $staff = DistribuidoraStaff::with('usuario')->findOrFail($staffId);

        if ($staff->tipo !== 'empleado') {
            throw new OperacionInvalidaException('Solo se pueden eliminar empleados desde esta pantalla.', 422);
        }

        if ((int) $staff->usuario_id === (int) Auth::id()) {
            throw new OperacionInvalidaException('No puedes eliminar tu propia cuenta.', 422);
        }

        $usuario = $staff->usuario;
        $nombre = $usuario?->nombreVisible() ?? 'Este empleado';

        $pedidos = DB::table('pedidos')->where('capturado_por_staff_id', $staff->id)->count();
        if ($pedidos > 0) {
            throw new OperacionInvalidaException(
                "{$nombre} capturó {$pedidos} ".($pedidos === 1 ? 'pedido' : 'pedidos').' y no se puede eliminar sin perder historial. Dale de baja: ya no podrá entrar y su historial se conserva.',
                422,
            );
        }

        $previos = [
            'nombre' => $usuario?->nombreVisible(),
            'email'  => $usuario?->email,
            'estado' => $staff->estado,
        ];
        $foto = $usuario?->foto_url;

        $cuentaBorrada = $this->conBloqueo($nombre, function () use ($staff, $usuario) {
            $staff->delete();

            return $usuario ? $this->soltarCuenta($usuario, (int) $staff->distribuidora_id) : false;
        });

        if ($cuentaBorrada) {
            $this->fotos->borrar($foto);
        }

        $this->auditoria->ejecutar(
            'empleado.eliminado_definitivamente', 'distribuidora_staff', (int) $staff->id,
            $previos, ['cuenta_borrada' => $cuentaBorrada],
        );
    }

    public function revendedor(int $afiliacionId): void
    {
        $afiliacion = RevendedorDistribuidora::with('revendedor.usuario')->findOrFail($afiliacionId);
        $revendedor = $afiliacion->revendedor;
        $nombre = $revendedor?->nombre ?? 'Este cliente mayorista';

        $dependencias = array_filter([
            'pedido'  => DB::table('pedidos')->where('revendedor_distribuidora_id', $afiliacion->id)->count(),
            'vale'    => DB::table('vales')->where('revendedor_distribuidora_id', $afiliacion->id)->count(),
            'cambio'  => DB::table('solicitudes_cambio')->where('revendedor_distribuidora_id', $afiliacion->id)->count(),
        ]);

        if ($dependencias !== []) {
            $partes = [];
            foreach ($dependencias as $tipo => $total) {
                $plural = ['pedido' => 'pedidos', 'vale' => 'vales', 'cambio' => 'solicitudes de cambio'][$tipo];
                $singular = ['pedido' => 'pedido', 'vale' => 'vale', 'cambio' => 'solicitud de cambio'][$tipo];
                $partes[] = $total.' '.($total === 1 ? $singular : $plural);
            }

            throw new OperacionInvalidaException(
                "{$nombre} tiene ".$this->unir($partes).' y no se puede eliminar sin perder historial. Dale de baja: ya no podrá entrar y su historial se conserva.',
                422,
            );
        }

        $previos = [
            'nombre'         => $revendedor?->nombre,
            'telefono'       => $revendedor?->telefono,
            'email'          => $revendedor?->emailVisible(),
            'codigo_interno' => $afiliacion->codigo_interno,
            'estado'         => $afiliacion->estado,
        ];
        $foto = $revendedor?->foto_url;

        [$contactoBorrado, $cuentaBorrada] = $this->conBloqueo($nombre, function () use ($afiliacion, $revendedor) {
            $afiliacion->delete();

            if (! $revendedor) {
                return [false, false];
            }

            // La persona (revendedores) es global: si sigue afiliada a otra
            // distribuidora, se queda.
            $otras = RevendedorDistribuidora::withoutGlobalScopes()
                ->where('revendedor_id', $revendedor->id)
                ->exists();

            if ($otras) {
                return [false, false];
            }

            $usuario = $revendedor->usuario_id
                ? Usuario::withoutGlobalScopes()->find($revendedor->usuario_id)
                : null;

            Revendedor::withoutGlobalScopes()->whereKey($revendedor->id)->delete();

            return [true, $usuario ? $this->soltarCuenta($usuario, (int) $afiliacion->distribuidora_id) : false];
        });

        if ($contactoBorrado) {
            $this->fotos->borrar($foto);
        }

        $this->auditoria->ejecutar(
            'revendedor.eliminado_definitivamente', 'revendedor_distribuidora', (int) $afiliacion->id,
            $previos, ['contacto_borrado' => $contactoBorrado, 'cuenta_borrada' => $cuentaBorrada],
        );
    }

    public function clienteDirecto(int $clienteId): void
    {
        $cliente = ClienteDirecto::with('usuario')->findOrFail($clienteId);
        $nombre = $cliente->nombre ?: 'Este cliente directo';

        $dependencias = array_filter([
            'pedido' => DB::table('pedidos')->where('cliente_directo_id', $cliente->id)->count(),
            'vale'   => DB::table('vales')->where('cliente_directo_id', $cliente->id)->count(),
            'cambio' => DB::table('solicitudes_cambio')->where('cliente_directo_id', $cliente->id)->count(),
            'venta'  => DB::table('ventas_directas')->where('cliente_directo_id', $cliente->id)->count(),
        ]);

        if ($dependencias !== []) {
            $nombres = [
                'pedido' => ['pedido', 'pedidos'],
                'vale'   => ['vale', 'vales'],
                'cambio' => ['solicitud de cambio', 'solicitudes de cambio'],
                'venta'  => ['venta', 'ventas'],
            ];
            $partes = [];
            foreach ($dependencias as $tipo => $total) {
                $partes[] = $total.' '.$nombres[$tipo][$total === 1 ? 0 : 1];
            }

            throw new OperacionInvalidaException(
                "{$nombre} tiene ".$this->unir($partes).' y no se puede eliminar sin perder historial. Dale de baja: ya no podrá entrar y su historial se conserva.',
                422,
            );
        }

        $previos = [
            'nombre'   => $cliente->nombre,
            'telefono' => $cliente->telefono,
            'email'    => $cliente->emailVisible(),
            'estado'   => $cliente->estado,
        ];
        $foto = $cliente->foto_url;
        $usuario = $cliente->usuario_id
            ? Usuario::withoutGlobalScopes()->find($cliente->usuario_id)
            : null;

        $cuentaBorrada = $this->conBloqueo($nombre, function () use ($cliente, $usuario) {
            $cliente->delete();

            return $usuario ? $this->soltarCuenta($usuario, (int) $cliente->distribuidora_id) : false;
        });

        $this->fotos->borrar($foto);

        $this->auditoria->ejecutar(
            'cliente_directo.eliminado_definitivamente', 'cliente_directo', (int) $cliente->id,
            $previos, ['cuenta_borrada' => $cuentaBorrada],
        );
    }

    /**
     * Quita a la cuenta todo lo de esta distribuidora y la borra si ya no
     * pertenece a nadie más. Regresa si se borró.
     */
    private function soltarCuenta(Usuario $usuario, int $distribuidoraId): bool
    {
        $usuario->tokens()->delete();

        DB::table(config('permission.table_names.model_has_roles', 'model_has_roles'))
            ->where('model_id', $usuario->id)
            ->where('model_type', $usuario->getMorphClass())
            ->where(config('permission.column_names.team_foreign_key', 'team_id'), $distribuidoraId)
            ->delete();
        DB::table(config('permission.table_names.model_has_permissions', 'model_has_permissions'))
            ->where('model_id', $usuario->id)
            ->where('model_type', $usuario->getMorphClass())
            ->where(config('permission.column_names.team_foreign_key', 'team_id'), $distribuidoraId)
            ->delete();

        $sigueEnUso = DistribuidoraStaff::withoutGlobalScopes()->where('usuario_id', $usuario->id)->exists()
            || Revendedor::withoutGlobalScopes()->where('usuario_id', $usuario->id)->exists()
            || ClienteDirecto::withoutGlobalScopes()->where('usuario_id', $usuario->id)->exists();

        if ($sigueEnUso) {
            return false;
        }

        // Si algo más la referencia (bitácoras con restrict, importaciones…),
        // la cuenta se queda inactiva en vez de tumbar todo el borrado.
        try {
            DB::transaction(function () use ($usuario) {
                DB::table(config('permission.table_names.model_has_roles', 'model_has_roles'))
                    ->where('model_id', $usuario->id)->where('model_type', $usuario->getMorphClass())->delete();
                DB::table(config('permission.table_names.model_has_permissions', 'model_has_permissions'))
                    ->where('model_id', $usuario->id)->where('model_type', $usuario->getMorphClass())->delete();
                $usuario->delete();
            });

            return true;
        } catch (QueryException $e) {
            if (! $this->esLlaveForanea($e)) {
                throw $e;
            }

            $usuario->forceFill(['estado' => 'inactivo'])->save();

            return false;
        }
    }

    private function conBloqueo(string $nombre, callable $borrar): mixed
    {
        try {
            return DB::transaction($borrar);
        } catch (QueryException $e) {
            if (! $this->esLlaveForanea($e)) {
                throw $e;
            }

            throw new OperacionInvalidaException($nombre.' '.self::MENSAJE_EN_USO, 422);
        }
    }

    private function esLlaveForanea(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === '23000' && (int) ($e->errorInfo[1] ?? 0) === 1451;
    }

    private function unir(array $partes): string
    {
        if (count($partes) <= 1) {
            return implode('', $partes);
        }

        $ultima = array_pop($partes);

        return implode(', ', $partes).' y '.$ultima;
    }
}
