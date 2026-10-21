<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Auditoría 21-oct-2026 (punto 5) — Listados de la API con paginación.
 *
 * Compatible con lo que ya usa la app: la lista sigue en "data". Se agregan
 * "meta" (página actual, última, por página y total) y "links" (siguiente y
 * anterior). Sin ?page= regresa la página 1 con 100 registros, igual que el
 * tope que había antes, para que las versiones viejas de la app sigan igual.
 */
class RespuestaPaginada
{
    public const POR_PAGINA = 100;

    public const MAXIMO_POR_PAGINA = 100;

    public static function paginar(Builder $consulta, Request $request): LengthAwarePaginator
    {
        $porPagina = (int) $request->query('per_page', self::POR_PAGINA);
        $porPagina = max(1, min(self::MAXIMO_POR_PAGINA, $porPagina));

        return $consulta->paginate($porPagina)->withQueryString();
    }

    /** @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>  $recurso */
    public static function json(LengthAwarePaginator $pagina, string $recurso): JsonResponse
    {
        return response()->json([
            'data'  => $recurso::collection($pagina->getCollection()),
            'meta'  => [
                'current_page' => $pagina->currentPage(),
                'last_page'    => $pagina->lastPage(),
                'per_page'     => $pagina->perPage(),
                'total'        => $pagina->total(),
            ],
            'links' => [
                'next' => $pagina->nextPageUrl(),
                'prev' => $pagina->previousPageUrl(),
            ],
        ]);
    }
}
