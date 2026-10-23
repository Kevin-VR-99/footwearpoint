<?php

namespace App\Services\Distribuidora;

use App\Models\Distribuidora;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

/**
 * Borra una distribuidora y lo que es solo suyo: pedidos, ventas, clientes,
 * personal, sucursales y sus cuentas si ya no pertenecen a otra.
 *
 * El catálogo compartido (líneas, marcas, productos, temporadas) no se toca.
 * Las tablas que impiden el borrado se vacían primero; el resto cae en cascada.
 */
class EliminarDistribuidoraAction
{
    /** Hijos con llave RESTRICT hacia la distribuidora o hacia sus padres. */
    private const ANTES_DE_LA_CASA = [
        'solicitudes_cambio',
        'movimientos_stock',
        'pagos',
        'vales',
        'pedidos',
        'ventas_directas',
        'stock_local',
        'cajas_movimientos',
        'cajas_sesiones',
        'conteos_inventario',
        'ciclos_compra',
        'suscripciones',
    ];

    public function ejecutar(Distribuidora $distribuidora): string
    {
        $nombre = $distribuidora->nombre_comercial;
        $logotipo = $distribuidora->logotipo_url;
        $id = $distribuidora->id;

        DB::transaction(function () use ($id) {
            $actual = Distribuidora::query()->lockForUpdate()->findOrFail($id);
            $usuarios = $this->usuariosVinculados($id);
            $revendedores = DB::table('revendedor_distribuidora')
                ->where('distribuidora_id', $id)
                ->pluck('revendedor_id')
                ->all();

            foreach (self::ANTES_DE_LA_CASA as $tabla) {
                DB::table($tabla)->where('distribuidora_id', $id)->delete();
            }

            $actual->delete();

            $this->quitarRevendedoresSinCasa($revendedores);
            $this->quitarRolesDelEquipo($id);
            $this->quitarCuentasSolas($usuarios);
        });

        $this->quitarArchivoLogotipo($logotipo);

        return $nombre;
    }

    /** @return list<int> */
    private function usuariosVinculados(int $distribuidoraId): array
    {
        $staff = DB::table('distribuidora_staff')
            ->where('distribuidora_id', $distribuidoraId)
            ->pluck('usuario_id');

        $clientes = DB::table('clientes_directos')
            ->where('distribuidora_id', $distribuidoraId)
            ->pluck('usuario_id');

        $revendedores = DB::table('revendedor_distribuidora')
            ->where('distribuidora_id', $distribuidoraId)
            ->pluck('revendedor_id');

        $cuentas = DB::table('revendedores')
            ->whereIn('id', $revendedores)
            ->pluck('usuario_id');

        return $staff->merge($clientes)->merge($cuentas)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @param  list<int>  $revendedorIds */
    private function quitarRevendedoresSinCasa(array $revendedorIds): void
    {
        foreach ($revendedorIds as $revendedorId) {
            $sigueEnOtra = DB::table('revendedor_distribuidora')
                ->where('revendedor_id', $revendedorId)
                ->exists();

            if ($sigueEnOtra) {
                continue;
            }

            $privados = DB::table('clientes_privados_revendedor')
                ->where('revendedor_id', $revendedorId)
                ->pluck('id');

            $usadoEnOtroPedido = $privados->isNotEmpty() && DB::table('pedido_cliente_privado')
                ->whereIn('cliente_privado_id', $privados)
                ->exists();

            if ($usadoEnOtroPedido) {
                continue;
            }

            DB::table('revendedores')->where('id', $revendedorId)->delete();
        }
    }

    private function quitarRolesDelEquipo(int $distribuidoraId): void
    {
        DB::table('model_has_roles')->where('team_id', $distribuidoraId)->delete();
        DB::table('model_has_permissions')->where('team_id', $distribuidoraId)->delete();

        $roles = DB::table('roles')->where('team_id', $distribuidoraId)->pluck('id');

        if ($roles->isNotEmpty()) {
            DB::table('roles')->whereIn('id', $roles)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @param  list<int>  $usuarioIds */
    private function quitarCuentasSolas(array $usuarioIds): void
    {
        foreach ($usuarioIds as $usuarioId) {
            if (! $this->cuentaSePuedeBorrar($usuarioId)) {
                continue;
            }

            DB::table('aceptaciones_legales')->where('usuario_id', $usuarioId)->delete();

            $email = DB::table('usuarios')->where('id', $usuarioId)->value('email');

            if (is_string($email) && $email !== '') {
                DB::table('password_reset_tokens')->where('email', $email)->delete();
            }

            DB::table('personal_access_tokens')
                ->where('tokenable_type', Usuario::class)
                ->where('tokenable_id', $usuarioId)
                ->delete();

            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $usuarioId)->delete();
            }

            DB::table('model_has_roles')
                ->where('model_type', Usuario::class)
                ->where('model_id', $usuarioId)
                ->delete();
            DB::table('model_has_permissions')
                ->where('model_type', Usuario::class)
                ->where('model_id', $usuarioId)
                ->delete();

            DB::table('usuarios')->where('id', $usuarioId)->delete();
        }
    }

    private function cuentaSePuedeBorrar(int $usuarioId): bool
    {
        $esAdminGeneral = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', Usuario::class)
            ->where('model_has_roles.model_id', $usuarioId)
            ->where('roles.name', 'admin_general')
            ->exists();

        if ($esAdminGeneral) {
            return false;
        }

        if (DB::table('distribuidora_staff')->where('usuario_id', $usuarioId)->exists()) {
            return false;
        }

        if (DB::table('clientes_directos')->where('usuario_id', $usuarioId)->exists()) {
            return false;
        }

        if (DB::table('revendedores')->where('usuario_id', $usuarioId)->exists()) {
            return false;
        }

        return ! DB::table('importaciones_catalogo')
            ->where('iniciada_por_usuario_id', $usuarioId)
            ->orWhere('revisada_por_usuario_id', $usuarioId)
            ->exists();
    }

    private function quitarArchivoLogotipo(?string $url): void
    {
        $marca = 'distribuidoras/logotipos/';
        $posicion = $url === null ? false : strpos($url, $marca);

        if ($posicion === false) {
            return;
        }

        try {
            Storage::disk('s3')->delete(substr($url, $posicion));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
