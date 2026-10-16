<?php

namespace App\Http\Resources;

use App\Services\Tienda\EnlaceTienda;
use App\Support\MapaUbicacion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketplaceDistribuidoraResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'nombre_comercial'    => $this->nombre_comercial,
            // TG-234 (G15): para abrir su tienda pública (null si no tiene).
            'slug'                => $this->slug,
            'url_tienda'          => app(EnlaceTienda::class)->url($this->resource),
            'logotipo_url'        => $this->logotipo_url,
            'descripcion_publica' => $this->descripcion_publica,
            'telefono_publico'    => $this->telefono_publico,
            'email_publico'       => $this->email_publico,
            'direccion_publica'   => $this->direccion_publica,
            // TG-269: búsqueda en Google Maps ("Ver en mapa"); null si no hay dirección.
            'url_mapa'            => MapaUbicacion::urlVerEnMapa($this->resource),
            'horario_publico'     => $this->horario_publico,
            // Las categorías del directorio ya no se usan. La lista queda vacía
            // para no romper a quien todavía lee esta clave.
            'categorias'          => [],
        ];
    }
}