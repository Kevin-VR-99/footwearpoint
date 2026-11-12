<?php

namespace App\Services\Cliente;

use App\Models\ClienteDirecto;
use App\Models\Usuario;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Support\TelefonoCliente;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Depura teléfonos de clientes directos antes del índice único.
 *
 * 1. Se normaliza a los últimos 10 dígitos.
 * 2. Si queda vacío o no tiene 10 dígitos, se guarda null.
 * 3. Si el mismo número queda en más de un cliente de la misma distribuidora,
 *    se conserva el de actividad más reciente (último pedido, último uso del
 *    token de la app, o la última actualización del registro). Si empatan,
 *    se conserva el id menor. A los demás se les pone null y, si tienen
 *    cuenta, se les pide recapturar el teléfono al entrar.
 * 4. Cada cambio queda en auditorias con el teléfono anterior.
 *
 * No escribe nada hasta que se llama aplicar().
 */
class DepurarTelefonosClientesDirectos
{
    public const ACCION_AUDITORIA = 'cliente_directo.telefono_depurado';

    public function __construct(private RegistrarAuditoriaAction $auditoria)
    {
    }

    /**
     * @return list<array{id:int,distribuidora_id:int,nombre:string,anterior:?string,nuevo:?string,motivo:string,conserva_id:?int}>
     */
    public function pendientes(): array
    {
        $filas = ClienteDirecto::withoutGlobalScopes()->orderBy('id')->get();
        $actividad = $this->actividad($filas);
        $cambios = [];
        $porGrupo = [];

        foreach ($filas as $fila) {
            $nuevo = TelefonoCliente::normalizar($fila->telefono);
            $porGrupo[$fila->distribuidora_id][$nuevo ?? ''][] = $fila;
            if ($nuevo === $fila->telefono) {
                continue;
            }
            $cambios[$fila->id] = $this->cambio($fila, $nuevo, $nuevo === null ? 'invalido' : 'normalizado', null);
        }

        foreach ($porGrupo as $grupos) {
            foreach ($grupos as $clave => $grupo) {
                if ($clave === '' || count($grupo) < 2) {
                    continue;
                }

                usort($grupo, function (ClienteDirecto $a, ClienteDirecto $b) use ($actividad) {
                    $marcaA = $actividad[$a->id] ?? '1970-01-01 00:00:00';
                    $marcaB = $actividad[$b->id] ?? '1970-01-01 00:00:00';
                    $comparacion = strcmp($marcaB, $marcaA);

                    return $comparacion !== 0 ? $comparacion : $a->id <=> $b->id;
                });

                $conserva = $grupo[0];
                $normalizado = TelefonoCliente::normalizar($conserva->telefono);
                $cambios[$conserva->id] = $this->cambio($conserva, $normalizado, 'conservado', $conserva->id);
                if ($normalizado === $conserva->telefono) {
                    unset($cambios[$conserva->id]);
                }

                foreach (array_slice($grupo, 1) as $perdedor) {
                    $cambios[$perdedor->id] = $this->cambio($perdedor, null, 'duplicado', $conserva->id);
                }
            }
        }

        $lista = array_values($cambios);
        usort($lista, fn (array $a, array $b) => $a['id'] <=> $b['id']);

        return $lista;
    }

    public function aplicar(): int
    {
        $pendientes = $this->pendientes();

        usort($pendientes, fn (array $a, array $b) => ($a['nuevo'] === null ? 0 : 1) <=> ($b['nuevo'] === null ? 0 : 1));

        DB::transaction(function () use ($pendientes) {
            foreach ($pendientes as $cambio) {
                $datos = [
                    'telefono' => $cambio['nuevo'],
                ];

                if ($cambio['motivo'] === 'duplicado') {
                    $datos['telefono_por_recapturar'] = $cambio['tiene_cuenta'];
                }

                if ($cambio['motivo'] === 'conservado' || $cambio['nuevo'] !== null) {
                    $datos['telefono_por_recapturar'] = false;
                }

                ClienteDirecto::withoutGlobalScopes()->whereKey($cambio['id'])->update($datos);

                Tenant::forzar($cambio['distribuidora_id'], function () use ($cambio) {
                    $this->auditoria->ejecutar(
                        self::ACCION_AUDITORIA,
                        'cliente_directo',
                        $cambio['id'],
                        ['telefono' => $cambio['anterior']],
                        ['telefono' => $cambio['nuevo'], 'motivo' => $cambio['motivo'], 'conserva_id' => $cambio['conserva_id']],
                    );
                });
            }
        });

        return count($pendientes);
    }

    /** @param  \Illuminate\Support\Collection<int, ClienteDirecto>  $filas */
    private function actividad($filas): array
    {
        $ids = $filas->pluck('id')->all();
        $marcas = [];

        if ($ids !== []) {
            $pedidos = DB::table('pedidos')
                ->selectRaw('cliente_directo_id, MAX(COALESCE(fecha_colocacion, updated_at, created_at)) as marca')
                ->whereIn('cliente_directo_id', $ids)
                ->groupBy('cliente_directo_id')
                ->pluck('marca', 'cliente_directo_id');

            foreach ($pedidos as $id => $marca) {
                $marcas[(int) $id] = (string) $marca;
            }
        }

        $usuarios = $filas->pluck('usuario_id', 'id')->filter();

        if ($usuarios->isNotEmpty() && DB::getSchemaBuilder()->hasTable('personal_access_tokens')) {
            $tokens = DB::table('personal_access_tokens')
                ->selectRaw('tokenable_id, MAX(COALESCE(last_used_at, created_at)) as marca')
                ->where('tokenable_type', Usuario::class)
                ->whereIn('tokenable_id', $usuarios->values()->all())
                ->groupBy('tokenable_id')
                ->pluck('marca', 'tokenable_id');

            foreach ($usuarios as $clienteId => $usuarioId) {
                $marca = $tokens[$usuarioId] ?? null;
                if ($marca !== null && strcmp((string) $marca, $marcas[(int) $clienteId] ?? '') > 0) {
                    $marcas[(int) $clienteId] = (string) $marca;
                }
            }
        }

        foreach ($filas as $fila) {
            $actualizacion = optional($fila->updated_at)->format('Y-m-d H:i:s') ?? '1970-01-01 00:00:00';
            if (strcmp($actualizacion, $marcas[$fila->id] ?? '') > 0) {
                $marcas[$fila->id] = $actualizacion;
            }
        }

        return $marcas;
    }

    /** @return array{id:int,distribuidora_id:int,nombre:string,anterior:?string,nuevo:?string,motivo:string,conserva_id:?int,tiene_cuenta:bool} */
    private function cambio(ClienteDirecto $fila, ?string $nuevo, string $motivo, ?int $conservaId): array
    {
        return [
            'id' => (int) $fila->id,
            'distribuidora_id' => (int) $fila->distribuidora_id,
            'nombre' => (string) $fila->nombre,
            'anterior' => $fila->telefono,
            'nuevo' => $nuevo,
            'motivo' => $motivo,
            'conserva_id' => $conservaId,
            'tiene_cuenta' => $fila->usuario_id !== null,
        ];
    }
}
