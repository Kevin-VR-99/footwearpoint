<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/**
 * Temporada global del catálogo: solo hay dos por año y son las mismas para
 * TODAS las líneas.
 *
 *   - Primavera-Verano {año}: del 1 de enero al 30 de junio.
 *   - Otoño-Invierno {año}:   del 1 de julio al 31 de diciembre.
 *
 * La temporada actual se calcula SOLO con la fecha de hoy en la zona de
 * México (America/Mexico_City): no hay botón para activarla ni un proceso
 * programado que la cambie. El renglón de la tabla se crea solo la primera
 * vez que se necesita (al consultar la actual o la siguiente).
 *
 * Cada línea publica sus productos en su propia "campaña" (tabla campanas),
 * que siempre cuelga de una temporada global y se crea automáticamente.
 */
class Temporada extends Model
{
    public const ZONA = 'America/Mexico_City';

    public const PRIMAVERA_VERANO = 'Primavera-Verano';

    public const OTONO_INVIERNO = 'Otoño-Invierno';

    protected $table = 'temporadas';

    protected $fillable = ['nombre', 'fecha_inicio', 'fecha_fin'];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
    ];

    /** Solo la temporada que contiene el día de hoy (zona de México). */
    public function scopeVigente($consulta)
    {
        $hoy = self::hoy()->toDateString();

        return $consulta->whereDate('temporadas.fecha_inicio', '<=', $hoy)->whereDate('temporadas.fecha_fin', '>=', $hoy);
    }

    public function campanas()
    {
        return $this->hasMany(Campana::class, 'temporada_id');
    }

    /** Hoy en la zona de México. */
    public static function hoy(): CarbonImmutable
    {
        return CarbonImmutable::now(self::ZONA)->startOfDay();
    }

    /**
     * Nombre y fechas de la temporada que contiene ese día (sin tocar la base).
     *
     * @return array{nombre: string, fecha_inicio: string, fecha_fin: string}
     */
    public static function datosParaFecha(CarbonInterface|string $fecha): array
    {
        $dia = is_string($fecha) ? CarbonImmutable::parse($fecha, self::ZONA) : CarbonImmutable::instance($fecha);
        $anio = (int) $dia->format('Y');

        return (int) $dia->format('n') <= 6
            ? ['nombre' => self::PRIMAVERA_VERANO.' '.$anio, 'fecha_inicio' => "{$anio}-01-01", 'fecha_fin' => "{$anio}-06-30"]
            : ['nombre' => self::OTONO_INVIERNO.' '.$anio, 'fecha_inicio' => "{$anio}-07-01", 'fecha_fin' => "{$anio}-12-31"];
    }

    /** La temporada (renglón) que contiene ese día; la crea si aún no existe. */
    public static function paraFecha(CarbonInterface|string $fecha): self
    {
        $datos = self::datosParaFecha($fecha);

        $temporada = static::query()->where('fecha_inicio', $datos['fecha_inicio'])->first();

        if (! $temporada) {
            try {
                $temporada = static::query()->create($datos);
                app(\App\Services\Auditoria\AuditarCambios::class)->creado($temporada, 'catalogo.temporada_global.creada', 'temporada');
            } catch (QueryException) {
                // Otra petición la creó al mismo tiempo (índice único).
                $temporada = static::query()->where('fecha_inicio', $datos['fecha_inicio'])->firstOrFail();
            }
        }

        return $temporada;
    }

    /** La temporada vigente hoy (por fecha, zona de México). */
    public static function actual(): self
    {
        return self::paraFecha(self::hoy());
    }

    /** La que sigue a la actual: el admin puede cargar su catálogo por adelantado. */
    public static function siguiente(): self
    {
        return self::actual()->posterior();
    }

    public function posterior(): self
    {
        return self::paraFecha(CarbonImmutable::parse($this->fecha_fin->toDateString(), self::ZONA)->addDay());
    }

    public function anterior(): self
    {
        return self::paraFecha(CarbonImmutable::parse($this->fecha_inicio->toDateString(), self::ZONA)->subDay());
    }

    public function esActual(): bool
    {
        return $this->fecha_inicio->toDateString() === self::datosParaFecha(self::hoy())['fecha_inicio'];
    }

    public function esFutura(): bool
    {
        return $this->fecha_inicio->toDateString() > self::hoy()->toDateString();
    }

    /** 'actual', 'proxima' o 'pasada', para mostrar en pantalla. */
    public function vigencia(): string
    {
        return match (true) {
            $this->esActual() => 'actual',
            $this->esFutura() => 'proxima',
            default => 'pasada',
        };
    }

    public function etiquetaVigencia(): string
    {
        return ['actual' => 'Actual', 'proxima' => 'Próxima', 'pasada' => 'Terminada'][$this->vigencia()];
    }
}
