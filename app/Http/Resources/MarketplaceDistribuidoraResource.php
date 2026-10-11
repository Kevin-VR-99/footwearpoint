<?php

namespace App\Http\Resources;

use App\Services\Tienda\EnlaceTienda;
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
            'horario_publico'     => $this->horario_publico,
            // TG-197 (G16): solo sus categorías activas, por nombre.
            'categorias'          => $this->categoriasActivas()
                ->map(fn ($categoria) => ['id' => $categoria->id, 'nombre' => $categoria->nombre])
                ->values(),
        ];
    }

    /** Usa las ya cargadas (DirectorioPublico las trae) o las consulta. */
    private function categoriasActivas()
    {
        $categorias = $this->relationLoaded('categoriasDirectorio')
            ? $this->categoriasDirectorio
            : $this->categoriasDirectorio()->orderBy('nombre')->get();

        return $categorias->filter(fn ($categoria) => $categoria->activa);
    }
}