<?php

namespace App\Http\Controllers\Api\Catalogo;

use App\Http\Controllers\Controller;
use App\Http\Resources\Catalogo\CatalogoResource;
use App\Models\ProductoCampana;
use App\Support\Tenant;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CatalogoController extends Controller
{
    /**
     * NOTA: el documento de tareas nombra este endpoint como
     * "GET /api/catalogo?distribuidora_id=", pero ESO CONTRADICE la
     * sección 1.8 del mismo documento (nunca resolver el tenant desde la
     * URL). Aquí se ignora ese parámetro a propósito y se resuelve la
     * distribuidora igual que en todo el resto del proyecto: a partir del
     * usuario autenticado (Tenant::id()).
     */
    public function index(): AnonymousResourceCollection
    {
        // Defensa extra: aunque hoy nadie con Tenant::id() nulo debería
        // llegar aquí (la ruta ya exige admin_distribuidora o empleado,
        // ambos siempre resuelven distribuidora), se verifica de todas
        // formas — mismo criterio que en el Bloque 1.
        abort_if(Tenant::id() === null, 403, 'No se pudo determinar la distribuidora del usuario autenticado.');

        $productosCampana = ProductoCampana::where('publicado', true)
            ->whereHas('campana', fn($query) => $query->where('estado', 'activa'))
            ->with([
                'producto.marca',
                'producto.linea',
                'producto.categoria',
                'imagenes',
                'disponibilidadPorVariante.variante.talla',
                'disponibilidadPorVariante.variante.color',
            ])
            ->get();

        return CatalogoResource::collection($productosCampana);
    }
}
