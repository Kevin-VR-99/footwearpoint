<?php

namespace App\Models;

use Carbon\Carbon;
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

    /**
     * Los estados en los que hay un worker trabajando sobre este catálogo y
     * nadie más debe meter mano: ni quitarlo, ni mandar otra tanda.
     *
     * Es una constante y no una lista suelta en cada pantalla porque antes
     * solo existía «procesando»; al llegar «aprobando» (TG-377) había que
     * acordarse de los cuatro sitios que la nombraban, y de esos se olvida
     * uno siempre.
     */
    public const OCUPADOS = ['procesando', 'aprobando'];

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
        'resumen_aprobacion',
        'procesamiento_iniciado_at',
    ];

    protected $casts = [
        'resumen_aprobacion' => 'array',
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

    /* ----------------------------------------------------------------
     * TG-377 — Aprobar, que ahora corre en el worker.
     * ---------------------------------------------------------------- */

    public function estaAprobando(): bool
    {
        return $this->estado === 'aprobando';
    }

    /** Hay un worker encima: leyendo con IA o metiendo productos al catálogo. */
    public function estaOcupada(): bool
    {
        return in_array($this->estado, self::OCUPADOS, true);
    }

    /**
     * Cuántos productos del catálogo ya entraron y cuántos van a entrar.
     *
     * Se cuenta de las filas de verdad, no de un contador aparte: el worker
     * marca cada fila como «aprobado» dentro de su propia transacción, así
     * que esta cuenta no puede desfasarse de lo que de veras pasó. Un
     * contador en otra columna sí podría, y mentiría justo cuando el usuario
     * está mirando.
     *
     * @return array{hechos: int, total: int}
     */
    public function avanceDeAprobacion(): array
    {
        $porEstado = $this->productosStaging()
            ->selectRaw('estado, COUNT(*) as cuantos')
            ->whereIn('estado', ['corregido', 'aprobado'])
            ->groupBy('estado')
            ->pluck('cuantos', 'estado');

        $hechos = (int) ($porEstado['aprobado'] ?? 0);

        return [
            'hechos' => $hechos,
            'total'  => $hechos + (int) ($porEstado['corregido'] ?? 0),
        ];
    }

    public function porcentajeDeAprobacion(): int
    {
        ['hechos' => $hechos, 'total' => $total] = $this->avanceDeAprobacion();

        return $total < 1 ? 0 : min(100, (int) floor($hechos * 100 / $total));
    }

    /**
     * Si el worker está caído o nunca se levantó, esto se queda en
     * «aprobando» sin que nadie lo mueva. La pantalla lo avisa pasados dos
     * minutos sin avanzar, pero NO cambia el estado: quien manda ahí es el
     * worker, igual que en la lectura con IA.
     *
     * OJO CON DE DÓNDE SALE LA HORA. No puede ser el updated_at de ESTA fila:
     * el worker no la toca mientras trabaja, así que un catálogo de 166
     * productos (unos seis minutos) se declararía trabado a los dos. Lo que
     * sí se mueve, producto por producto, son las filas de staging; esa es la
     * señal de que algo está pasando.
     */
    public function aprobacionPareceTrabada(): bool
    {
        if (! $this->estaAprobando()) {
            return false;
        }

        $ultimoMovimiento = $this->productosStaging()->max('updated_at');
        $referencia = $ultimoMovimiento !== null
            ? Carbon::parse($ultimoMovimiento)->max($this->updated_at)
            : $this->updated_at;

        return $referencia !== null && $referencia->lte(now()->subSeconds(120));
    }

    public function textoDeAprobacion(): string
    {
        ['hechos' => $hechos, 'total' => $total] = $this->avanceDeAprobacion();

        if ($this->aprobacionPareceTrabada()) {
            return "Van {$hechos} de {$total} y lleva rato sin avanzar. "
                .'Puede que el servidor de tareas esté caído. '
                .'Lo que ya entró está guardado; avisa al equipo antes de volver a intentar.';
        }

        if ($total < 1) {
            return 'Preparando los productos.';
        }

        return "Van {$hechos} de {$total} productos. Esta página se actualiza sola.";
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
