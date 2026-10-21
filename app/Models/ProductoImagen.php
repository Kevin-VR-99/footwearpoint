<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Foto de un producto en una temporada; es del catálogo, la ven todas (TG-209). */
class ProductoImagen extends Model
{
    protected $table = 'producto_imagenes';

    // Esta tabla solo tiene created_at, no updated_at.
    const UPDATED_AT = null;

    protected $fillable = [
        'producto_campana_id',
        'url',
        'url_miniatura',
        'url_mediana',
        'orden',
        'es_principal',
    ];

    protected $casts = [
        'es_principal' => 'boolean',
    ];

    /** WebP de ~400 px; la original si la foto es anterior a las variantes. */
    public function urlMiniatura(): string
    {
        return (string) ($this->url_miniatura ?: $this->url);
    }

    /** WebP de ~1200 px; la original si la foto es anterior a las variantes. */
    public function urlMediana(): string
    {
        return (string) ($this->url_mediana ?: $this->url);
    }

    /** srcset para <img>: solo si existen las variantes. */
    public function srcset(): ?string
    {
        if (! $this->url_miniatura || ! $this->url_mediana) {
            return null;
        }

        return $this->url_miniatura.' 400w, '.$this->url_mediana.' 1200w';
    }

    public function productoCampana()
    {
        return $this->belongsTo(ProductoCampana::class, 'producto_campana_id');
    }
}