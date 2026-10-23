<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un catálogo de fábrica que se importó con IA (A6 a A9).
 *
 * No usa BelongsToTenant a propósito (TG-208): el catálogo es uno solo para
 * todo el sistema y solo el admin general lo importa, así que estas filas no
 * son de ninguna distribuidora.
 */
class ImportacionCatalogo extends Model
{
    protected $table = 'importaciones_catalogo';

    protected $fillable = [
        'linea_id',
        'campana_id',
        'archivo_url',
        'archivo_ia_id',
        'tipo_archivo',
        'proveedor_ia',
        'modelo_ia',
        'paginas',
        'paginas_leidas',
        'leyendo_hasta',
        'tokens_entrada',
        'tokens_salida',
        'costo_usd',
        'estado',
        'iniciada_por_usuario_id',
        'revisada_por_usuario_id',
        'mensaje_error',
        'procesamiento_iniciado_at',
    ];

    protected $casts = [
        'procesamiento_iniciado_at' => 'datetime',
        'paginas' => 'integer',
        'paginas_leidas' => 'integer',
        'leyendo_hasta' => 'integer',
        'tokens_entrada' => 'integer',
        'tokens_salida' => 'integer',
        'costo_usd' => 'decimal:4',
    ];

    public function linea()
    {
        return $this->belongsTo(Linea::class, 'linea_id');
    }

    /** La temporada a la que entran los productos importados. */
    public function campana()
    {
        return $this->belongsTo(Campana::class, 'campana_id');
    }

    public function iniciadaPor()
    {
        return $this->belongsTo(Usuario::class, 'iniciada_por_usuario_id');
    }

    public function revisadaPor()
    {
        return $this->belongsTo(Usuario::class, 'revisada_por_usuario_id');
    }

    public function productosStaging()
    {
        return $this->hasMany(ProductoImportadoStaging::class, 'importacion_id');
    }

    /** 0 a 100. Sin total todavía, la barra se queda en cero. */
    public function porcentajeDeAvance(): int
    {
        $total = (int) $this->paginas;

        if ($total < 1) {
            return 0;
        }

        return min(100, (int) floor(((int) $this->paginas_leidas) * 100 / $total));
    }

    /**
     * Una petición a la IA puede tardar lo que dice segundos_por_peticion sin
     * escribir nada. Pasado eso, más un margen, la pantalla avisa: no marca
     * error, porque quien lo decide es el worker.
     */
    public function pareceTrabada(): bool
    {
        if ($this->estado !== 'procesando' || $this->updated_at === null) {
            return false;
        }

        $peticion = max(60, (int) config('ia.segundos_por_peticion'));
        $enLectura = (int) $this->paginas_leidas > 0 || (int) $this->leyendo_hasta > 0;
        $limite = $enLectura ? $peticion + 120 : ($peticion * 2) + 120;

        return $this->updated_at->lte(now()->subSeconds($limite));
    }

    public function textoDeAvance(): string
    {
        if ($this->pareceTrabada()) {
            $minutos = max(1, (int) abs($this->updated_at->diffInMinutes(now())));
            $unidad = $minutos === 1 ? 'minuto' : 'minutos';

            return "Lleva {$minutos} {$unidad} sin avanzar. Puede haberse trabado. "
                .'La lectura sigue en el servidor; no la reintentes hasta que pase a error.';
        }

        $total = (int) $this->paginas;
        $leidas = (int) $this->paginas_leidas;

        if ($total < 1) {
            return 'Preparando el archivo. En un momento se sabe cuántas páginas tiene.';
        }

        if ($leidas >= $total) {
            $paginas = $total === 1 ? 'la página' : "las {$total} páginas";

            return "Ya leyó {$paginas}. Está preparando las fotos.";
        }

        $faltan = $total - $leidas;
        $de = $total === 1 ? '1 página' : "{$total} páginas";
        $van = "Van {$leidas} de {$de}.";
        $falta = $faltan === 1 ? 'Falta 1.' : "Faltan {$faltan}.";
        $rango = (int) $this->leyendo_hasta > 0
            ? " Leyendo hasta la página {$this->leyendo_hasta}."
            : '';

        return $van.' '.$falta.$rango;
    }
}
