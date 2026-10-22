<?php

namespace App\Services\Auth;

use App\Exceptions\SinPermisoModuloException;
use App\Models\Usuario;
use App\Services\Distribuidora\ProvisionarRolesDistribuidoraAction;
use App\Support\Tenant;
use Spatie\Permission\PermissionRegistrar;

/**
 * TG-246 — Permisos por módulo del personal (R4, TG-208).
 *
 * El admin de la distribuidora puede todo; al empleado se le dan uno por uno
 * (RolesPermissionsSeeder::PERMISOS_POR_MODULO). Antes solo el menú lo
 * respetaba: por URL, o con el botón de la pantalla, el empleado sin permiso
 * hacía lo mismo. Esta es la regla del servidor, en un solo lugar, para que
 * el menú, las pantallas y las acciones digan siempre lo mismo.
 */
class PermisoModuloService
{
    public const CERRAR_CICLO = 'ciclo.cerrar';
    public const SOLICITAR_FABRICA = 'ciclo.solicitar_fabrica';
    public const REPORTES_FINANCIEROS = 'reportes.ver_financieros';
    public const CONFIGURACION = 'configuracion.administrar';
    public const USUARIOS = 'usuarios.administrar';

    // Permisos finos por empleado. El admin los prende o apaga para cada
    // empleado (distribuidora_staff.permisos_finos). Si nunca se tocaron,
    // vale lo que dice PERMISOS_FINOS: los que el empleado ya podía hacer
    // antes de existir este control vienen prendidos, para no quitarle nada
    // a nadie; los nuevos y delicados, apagados.
    public const PAGOS_REGISTRAR = 'pagos.registrar';
    public const STOCK_AJUSTAR = 'stock.ajustar';
    public const COSTOS_VER = 'costos.ver';
    public const VENTAS_CANCELAR = 'ventas.cancelar';
    public const CAJA_OPERAR = 'caja.operar';
    public const INVENTARIO_CONTEO = 'inventario.conteo';
    public const CLIENTES_VER = 'clientes.ver';
    public const CLIENTES_IMPORTAR = 'clientes.importar';
    public const COMISIONES_VER = 'comisiones.ver';

    /** permiso => [etiqueta, prendido por omisión] */
    public const PERMISOS_FINOS = [
        self::PAGOS_REGISTRAR   => ['Registrar pagos de pedidos', true],
        self::STOCK_AJUSTAR     => ['Entradas y ajustes de stock', true],
        self::COSTOS_VER        => ['Ver precios de mayoreo', true],
        self::CAJA_OPERAR       => ['Abrir y cerrar caja (corte)', true],
        self::INVENTARIO_CONTEO => ['Inventario físico (conteos)', true],
        self::CLIENTES_VER      => ['Ver historial de clientes', true],
        self::VENTAS_CANCELAR   => ['Cancelar ventas de mostrador', false],
        self::CLIENTES_IMPORTAR => ['Importar clientes', false],
        self::COMISIONES_VER    => ['Ver comisiones y metas', false],
    ];

    /** Los de siempre (spatie), con su etiqueta para la pantalla de empleados. */
    public const ETIQUETAS_MODULO = [
        self::CERRAR_CICLO         => 'Cerrar ciclo',
        self::SOLICITAR_FABRICA    => 'Solicitar a fábrica',
        self::REPORTES_FINANCIEROS => 'Reportes financieros',
        self::CONFIGURACION        => 'Configuración',
        self::USUARIOS             => 'Usuarios',
    ];

    /**
     * Lo que vale cada permiso fino para un empleado, con los valores por
     * omisión aplicados.
     *
     * @return array<string, bool>
     */
    public static function finosDe(?\App\Models\DistribuidoraStaff $staff): array
    {
        $guardados = is_array($staff?->permisos_finos) ? $staff->permisos_finos : [];
        $resultado = [];

        foreach (self::PERMISOS_FINOS as $permiso => [, $porOmision]) {
            $resultado[$permiso] = array_key_exists($permiso, $guardados) ? (bool) $guardados[$permiso] : $porOmision;
        }

        return $resultado;
    }

    public function puede(?Usuario $usuario, string $permiso): bool
    {
        $distribuidoraId = Tenant::id();

        if ($usuario === null || $distribuidoraId === null) {
            return false;
        }

        $registrar = app(PermissionRegistrar::class);
        $equipoAnterior = $registrar->getPermissionsTeamId();

        try {
            // Los roles y permisos viven en el equipo de la distribuidora. Se
            // fija aquí porque en las peticiones de Livewire no siempre pasa
            // el middleware que lo hace.
            $registrar->setPermissionsTeamId($distribuidoraId);
            $usuario->unsetRelation('roles')->unsetRelation('permissions');

            if ($usuario->hasRole(ProvisionarRolesDistribuidoraAction::ROL_ADMINISTRADOR)) {
                return true;
            }

            if (array_key_exists($permiso, self::PERMISOS_FINOS)) {
                return $this->finoDelEmpleado($usuario, $distribuidoraId, $permiso);
            }

            return $usuario->hasPermissionTo($permiso);
        } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist) {
            return false;
        } finally {
            $registrar->setPermissionsTeamId($equipoAnterior);
            $usuario->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    /** Solo el personal activo de ESTA distribuidora; revendedores y clientes nunca. */
    private function finoDelEmpleado(Usuario $usuario, int $distribuidoraId, string $permiso): bool
    {
        $staff = \App\Models\DistribuidoraStaff::withoutGlobalScopes()
            ->where('distribuidora_id', $distribuidoraId)
            ->where('usuario_id', $usuario->id)
            ->where('estado', 'activo')
            ->first();

        if ($staff === null) {
            return false;
        }

        // Un administrador registrado como staff (sin el rol por algún dato viejo) puede todo.
        if ($staff->tipo === 'administrador') {
            return true;
        }

        return self::finosDe($staff)[$permiso] ?? false;
    }

    /** @throws SinPermisoModuloException */
    public function exigir(?Usuario $usuario, string $permiso): void
    {
        if (! $this->puede($usuario, $permiso)) {
            throw new SinPermisoModuloException();
        }
    }
}
