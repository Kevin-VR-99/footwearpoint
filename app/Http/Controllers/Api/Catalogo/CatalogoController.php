<?php

namespace App\Http\Controllers\Api\Catalogo;

use App\Http\Controllers\Controller;
use App\Http\Resources\Catalogo\CatalogoResource;
use App\Services\Catalogo\CatalogoVisible;
use App\Services\Catalogo\PrecioEfectivo;
use App\Support\Tenant;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * El catálogo como lo ve esta distribuidora (E4-05).
 *
 * NOTA: el documento de tareas nombra este endpoint como
 * "GET /api/catalogo?distribuidora_id=", pero ESO CONTRADICE la sección 1.8
 * del mismo documento (nunca resolver el tenant desde la URL). Aquí se ignora
 * ese parámetro a propósito y la distribuidora sale del usuario autenticado.
 *
 * Desde TG-213 el catálogo es compartido: qué productos se ven lo decide
 * CatalogoVisible y con qué precio, PrecioEfectivo. La respuesta conserva los
 * mismos campos de siempre para no romper la app.
 */
class CatalogoController extends Controller
{
    public function index(CatalogoVisible $catalogo, PrecioEfectivo $precios): AnonymousResourceCollection
    {
        abort_if(Tenant::id() === null, 403, 'No se pudo determinar la distribuidora del usuario autenticado.');

        $productosCampana = $catalogo->consulta()
            ->with([
                'producto.marca',
                'producto.categoria',
                'campana.linea',
                'imagenes',
                'disponibilidadPorVariante.variante.talla',
                'disponibilidadPorVariante.variante.color',
            ])
            ->get();

        // Los precios propios de todos, en una sola consulta.
        $precios->precargar($productosCampana);

        return CatalogoResource::collection($productosCampana);
    }
}
