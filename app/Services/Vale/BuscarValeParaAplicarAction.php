<?php

namespace App\Services\Vale;

use App\Exceptions\OperacionInvalidaException;
use App\Models\Vale;
use App\Support\Tenant;

/**
 * TG-250 (R8, E12-03) — Busca por folio el vale que se quiere usar en el punto
 * de venta y explica por qué no se puede usar, si es el caso.
 *
 * Solo sirven vales de CLIENTES DIRECTOS (decisión del PO): la venta de
 * mostrador solo puede quedar a nombre de un cliente directo.
 *
 * Las reglas de saldo y vencimiento son las mismas que en AplicarValeAction,
 * que vuelve a revisarlas al aplicar (con el vale bloqueado).
 */
class BuscarValeParaAplicarAction
{
    public function ejecutar(string $folio, bool $bloquear = false): Vale
    {
        abort_if(Tenant::id() === null, 403, 'No se pudo determinar la distribuidora.');

        $folio = trim($folio);

        if ($folio === '') {
            throw new OperacionInvalidaException('Escribe el folio del vale.', 422);
        }

        // BelongsToTenant ya limita a la distribuidora actual: un folio de
        // otra distribuidora responde igual que uno que no existe.
        // La collation de MySQL (utf8mb4_unicode_ci) ya no distingue mayúsculas,
        // así que se compara directo y se puede usar el índice de folio.
        $consulta = Vale::query()->with('clienteDirecto')->where('folio', $folio);

        if ($bloquear) {
            $consulta->lockForUpdate();
        }

        $vale = $consulta->first();

        if (! $vale) {
            throw new OperacionInvalidaException('No existe un vale con el folio '.$folio.'.', 422);
        }

        if (! $vale->cliente_directo_id) {
            throw new OperacionInvalidaException(
                'El vale '.$vale->folio.' es de un cliente mayorista; en el punto de venta solo se aceptan vales de clientes directos.',
                422
            );
        }

        $vencido = $vale->estado === 'vencido'
            || ($vale->fecha_vencimiento !== null && $vale->fecha_vencimiento->isPast());

        if ($vencido) {
            throw new OperacionInvalidaException('El vale '.$vale->folio.' está vencido.', 422);
        }

        if ($vale->estado === 'agotado' || (float) $vale->saldo_actual <= 0) {
            throw new OperacionInvalidaException('El vale '.$vale->folio.' ya no tiene saldo.', 422);
        }

        if ($vale->estado !== 'activo') {
            throw new OperacionInvalidaException('El vale '.$vale->folio.' no está activo.', 422);
        }

        return $vale;
    }
}
