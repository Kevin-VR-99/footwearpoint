<?php

namespace Database\Seeders;

use App\Models\Campana;
use App\Models\Distribuidora;
use App\Models\Linea;
use App\Models\ProductoCampana;
use App\Models\StockLocal;
use App\Models\Sucursal;
use App\Services\Distribuidora\ActivarLineaDistribuidoraAction;
use App\Services\Distribuidora\FijarDescuentoMayoristaAction;
use App\Services\Distribuidora\GestionarOfertaDistribuidoraAction;
use App\Support\Tenant;
use Illuminate\Database\Seeder;

/**
 * Lo que cada distribuidora demo hace con el catálogo maestro (TG-217).
 *
 * El catálogo lo deja CatalogoMaestroDemoSeeder y es uno solo. Aquí va la capa
 * de cada distribuidora: qué líneas vende (K3), su descuento de mayoreo, qué
 * producto tiene precio propio y qué producto esconde (K5), y algo de
 * existencia para poder vender en mostrador.
 *
 * Las dos venden "Impuls Deportivo", pero con descuento distinto y con su
 * propio precio para el mismo producto: así se ve de un jalón que el precio de
 * mayoreo es de cada una y no del catálogo.
 *
 * Se usan las mismas acciones que usa el panel, no inserts a mano: si mañana
 * cambia una regla (el cupo del plan, el tope del precio de mayoreo), la demo
 * se cae aquí y no en producción.
 */
class DemoCatalogoDistribuidorasSeeder extends Seeder
{
    public function run(): void
    {
        // Dos líneas cada una: es lo que incluye el plan Básico, así ninguna
        // demo arranca pagando línea extra.
        $this->paraDistribuidora(
            DemoDistribuidoraSeeder::SLUG,
            ['Impuls Deportivo', 'Impuls Escolar'],
            30.00,
            ['IR1458102' => 1990.00],
            ['JQ7143'],
        );

        $this->paraDistribuidora(
            DemoDistribuidoraSeeder::SEGUNDA_SLUG,
            ['Impuls Deportivo', 'Confort Dama'],
            20.00,
            ['IR1458102' => 2290.00],
            [],
        );
    }

    /**
     * @param  array<int, string>  $nombresLineas  líneas que activa
     * @param  array<string, float>  $preciosPropios  modelo => precio de mayoreo
     * @param  array<int, string>  $ocultos  modelos que no les muestra a sus clientes
     */
    private function paraDistribuidora(
        string $slug,
        array $nombresLineas,
        float $descuento,
        array $preciosPropios,
        array $ocultos,
    ): void {
        $distribuidora = Distribuidora::where('slug', $slug)->firstOrFail();

        Tenant::forzar($distribuidora->id, function () use ($distribuidora, $nombresLineas, $descuento, $preciosPropios, $ocultos) {
            $activar = app(ActivarLineaDistribuidoraAction::class);
            $ofertas = app(GestionarOfertaDistribuidoraAction::class);

            $lineas = [];

            foreach ($nombresLineas as $nombre) {
                $linea = Linea::where('nombre', $nombre)->firstOrFail();
                $activar->ejecutar($linea->id);
                $lineas[] = $linea;
            }

            app(FijarDescuentoMayoristaAction::class)->ejecutar($descuento);

            foreach ($preciosPropios as $modelo => $precio) {
                $ofertas->ponerPrecioMayorista($this->productoCampana($modelo)->id, $precio);
            }

            foreach ($ocultos as $modelo) {
                $ofertas->ocultar($this->productoCampana($modelo)->id);
            }

            $this->stock($distribuidora, $lineas);
        });

        $this->command?->info(
            "{$distribuidora->nombre_comercial}: ".count($nombresLineas)
            ." línea(s) activa(s), mayoreo al {$descuento}% de descuento."
        );
    }

    /** El producto de ese modelo en la temporada que está activa. */
    private function productoCampana(string $modelo): ProductoCampana
    {
        return ProductoCampana::query()
            ->whereRelation('producto', 'modelo', $modelo)
            ->whereRelation('campana', 'estado', 'activa')
            ->firstOrFail();
    }

    /**
     * Algo de existencia en la sucursal principal, para que se pueda vender en
     * mostrador sin tener que capturarla a mano. Solo de los primeros
     * productos de cada línea: la demo no necesita el inventario completo.
     *
     * @param  array<int, Linea>  $lineas
     */
    private function stock(Distribuidora $distribuidora, array $lineas): void
    {
        $sucursal = Sucursal::where('distribuidora_id', $distribuidora->id)
            ->where('es_principal', true)
            ->firstOrFail();

        foreach ($lineas as $linea) {
            $campana = Campana::where('linea_id', $linea->id)->where('estado', 'activa')->first();

            if (! $campana) {
                continue;
            }

            $productos = ProductoCampana::with('producto.variantes')
                ->where('campana_id', $campana->id)
                ->orderBy('id')
                ->limit(3)
                ->get();

            foreach ($productos as $productoCampana) {
                foreach ($productoCampana->producto->variantes as $variante) {
                    StockLocal::firstOrCreate(
                        [
                            'distribuidora_id' => $distribuidora->id,
                            'sucursal_id' => $sucursal->id,
                            'variante_id' => $variante->id,
                        ],
                        ['cantidad_disponible' => 10, 'stock_minimo' => 2]
                    );
                }
            }
        }
    }
}
