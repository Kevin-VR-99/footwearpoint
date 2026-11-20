<?php

namespace App\Services\Personal;

use App\Models\CajaSesion;
use App\Models\ClienteDirecto;
use App\Models\Distribuidora;
use App\Models\DistribuidoraStaff;
use App\Models\Revendedor;
use App\Models\RevendedorDistribuidora;
use App\Models\Usuario;
use App\Support\PersonalPlataforma;
use Illuminate\Validation\ValidationException;

/**
 * TG-338 (K40) — Lo que impide desactivar o borrar una cuenta del personal.
 *
 * Se revisa en cada asignación: una cuenta con varias se queda sin todas.
 * Corre dentro de la transacción de quien llama, con los bloqueos puestos.
 */
class SalvaguardasPersonal
{
    public const CAMPO = 'baja';

    /** @param  string  $accion  'desactivar' o 'borrar', para los mensajes. */
    public function exigir(Usuario $admin, Usuario $persona, string $accion): void
    {
        if ((int) $persona->id === (int) $admin->id) {
            $this->rechazar("No puedes {$accion} tu propia cuenta.");
        }

        $activa = $persona->estado === 'activo';

        if ($activa && PersonalPlataforma::esAdminGeneral($persona)) {
            $otro = Usuario::query()
                ->whereKey(PersonalPlataforma::adminsGeneralesBloqueados())
                ->whereKeyNot($persona->id)
                ->where('estado', 'activo')
                ->exists();

            if (! $otro) {
                $this->rechazar('Es el último admin general activo. Primero nombra a otro.');
            }
        }

        $filas = DistribuidoraStaff::withoutGlobalScopes()
            ->with(['distribuidora' => fn ($q) => $q->withoutGlobalScopes()])
            ->where('usuario_id', $persona->id)
            ->lockForUpdate()
            ->get();

        foreach ($filas as $fila) {
            $nombre = $this->nombre($fila->distribuidora);

            if ($activa && $fila->tipo === 'administrador' && $fila->estado === 'activo') {
                $otro = DistribuidoraStaff::withoutGlobalScopes()
                    ->where('distribuidora_id', $fila->distribuidora_id)
                    ->administradoresActivos()
                    ->where('usuario_id', '!=', $persona->id)
                    ->lockForUpdate()
                    ->exists();

                if (! $otro) {
                    $this->rechazar("Es el único admin activo de {$nombre}. Primero nombra a otro admin.");
                }
            }

            $cajaAbierta = CajaSesion::withoutGlobalScopes()
                ->where('abierta_por_staff_id', $fila->id)
                ->where('estado', 'abierta')
                ->exists();

            if ($cajaAbierta) {
                $this->rechazar("Tiene una caja abierta en {$nombre}. Primero debe hacer su corte.");
            }
        }

        $cliente = $this->distribuidoraComoCliente($persona);

        if ($cliente !== null) {
            $this->rechazar("Esta cuenta también tiene acceso como cliente en {$cliente}: {$accion}la le cortaría la app.");
        }
    }

    /** Las distribuidoras donde trabaja, para la advertencia. @return list<string> */
    public function asignaciones(Usuario $persona): array
    {
        return array_map(
            fn (array $rol) => $rol['etiqueta'].($rol['distribuidora'] ? ' en '.$rol['distribuidora'] : ''),
            PersonalPlataforma::rolesDe([(int) $persona->id])[$persona->id] ?? []
        );
    }

    /** Un acceso de cliente activo, como mayorista o cliente directo. */
    private function distribuidoraComoCliente(Usuario $persona): ?string
    {
        $revendedorId = Revendedor::withoutGlobalScopes()->where('usuario_id', $persona->id)->value('id');

        $distribuidoraId = $revendedorId
            ? RevendedorDistribuidora::withoutGlobalScopes()
                ->where('revendedor_id', $revendedorId)
                ->where('estado', 'activo')
                ->value('distribuidora_id')
            : null;

        $distribuidoraId ??= ClienteDirecto::withoutGlobalScopes()
            ->where('usuario_id', $persona->id)
            ->where('estado', 'activo')
            ->value('distribuidora_id');

        return $distribuidoraId === null
            ? null
            : $this->nombre(Distribuidora::withoutGlobalScopes()->find($distribuidoraId));
    }

    private function nombre(?Distribuidora $distribuidora): string
    {
        return (string) ($distribuidora?->nombre_comercial ?: ($distribuidora?->razon_social ?: 'su distribuidora'));
    }

    private function rechazar(string $mensaje): never
    {
        throw ValidationException::withMessages([self::CAMPO => [$mensaje]]);
    }
}
