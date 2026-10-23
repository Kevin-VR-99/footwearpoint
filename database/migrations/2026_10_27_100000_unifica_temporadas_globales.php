<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Temporadas globales: solo Primavera-Verano (1 ene - 30 jun) y
 * Otoño-Invierno (1 jul - 31 dic) de cada año, iguales para todas las líneas.
 *
 * 1. Crea la tabla temporadas.
 * 2. Quita la regla "una temporada activa por línea" (columna calculada
 *    linea_activa_id y su índice único): ahora lo que se vende lo decide la
 *    fecha, no el estado.
 * 3. Amarra cada fila de campanas a su temporada global (por el nombre, si
 *    dice Primavera/Otoño y el año; si no, por su fecha de inicio o de alta)
 *    y le pone el nombre y las fechas de la temporada ("Otoño-Invierno 2026"
 *    en lugar de "Impuls Otoño-Invierno 2026").
 * 4. Borra las campañas vacías que solo eran el "lugar" de una línea en la
 *    temporada (sin productos ni importaciones y no finalizadas/archivadas).
 *    Se vuelven a crear solas al importar. Nunca borra una fila referenciada.
 * 5. Deja temporada_id obligatorio, con llave a temporadas y único por
 *    (línea, temporada).
 *
 * Se puede correr más de una vez: cada paso revisa si ya se hizo. Funciona
 * igual en MariaDB (local) y MySQL 8 (Railway). Todo lo que cambia o borra en
 * datos queda en la auditoría.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('temporadas')) {
            Schema::create('temporadas', function (Blueprint $tabla) {
                $tabla->id();
                $tabla->string('nombre', 60);
                $tabla->date('fecha_inicio');
                $tabla->date('fecha_fin');
                $tabla->timestamps();

                $tabla->unique('nombre', 'uq_temporada_nombre');
                $tabla->unique('fecha_inicio', 'uq_temporada_inicio');
            });
        }

        if (Schema::hasColumn('campanas', 'linea_activa_id')) {
            if (Schema::hasIndex('campanas', 'uq_campana_una_activa_por_linea')) {
                DB::statement('ALTER TABLE `campanas` DROP INDEX `uq_campana_una_activa_por_linea`');
            }
            DB::statement('ALTER TABLE `campanas` DROP COLUMN `linea_activa_id`');
        }

        if (! Schema::hasColumn('campanas', 'temporada_id')) {
            Schema::table('campanas', function (Blueprint $tabla) {
                $tabla->unsignedBigInteger('temporada_id')->nullable()->after('linea_id');
            });
        }

        $this->amarrarCampanas();
        $this->borrarCampanasVaciasDeRelleno();

        // Solo si sigue aceptando NULL: al volver a correr no hay DDL de más.
        $columna = collect(Schema::getColumns('campanas'))->firstWhere('name', 'temporada_id');
        if ($columna['nullable'] ?? false) {
            DB::statement('ALTER TABLE `campanas` MODIFY `temporada_id` BIGINT UNSIGNED NOT NULL');
        }

        $llaves = collect(Schema::getForeignKeys('campanas'))->pluck('name')->all();
        if (! in_array('fk_campanas_temporada', $llaves, true)) {
            Schema::table('campanas', function (Blueprint $tabla) {
                $tabla->foreign('temporada_id', 'fk_campanas_temporada')
                    ->references('id')->on('temporadas')->onUpdate('restrict')->onDelete('restrict');
            });
        }

        if (! Schema::hasIndex('campanas', 'uq_campana_linea_temporada')) {
            Schema::table('campanas', function (Blueprint $tabla) {
                $tabla->unique(['linea_id', 'temporada_id'], 'uq_campana_linea_temporada');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('campanas', 'uq_campana_linea_temporada')) {
            Schema::table('campanas', fn (Blueprint $tabla) => $tabla->dropUnique('uq_campana_linea_temporada'));
        }

        if (in_array('fk_campanas_temporada', collect(Schema::getForeignKeys('campanas'))->pluck('name')->all(), true)) {
            Schema::table('campanas', fn (Blueprint $tabla) => $tabla->dropForeign('fk_campanas_temporada'));
        }

        if (Schema::hasColumn('campanas', 'temporada_id')) {
            Schema::table('campanas', fn (Blueprint $tabla) => $tabla->dropColumn('temporada_id'));
        }

        Schema::dropIfExists('temporadas');

        // Los nombres anteriores y las campañas vacías borradas no se
        // regresan (quedaron en la auditoría).
        if (! Schema::hasColumn('campanas', 'linea_activa_id')) {
            DB::statement(
                'ALTER TABLE `campanas` '
                ."ADD COLUMN `linea_activa_id` BIGINT UNSIGNED AS (IF(`estado` = 'activa', `linea_id`, NULL)) STORED, "
                .'ADD UNIQUE KEY `uq_campana_una_activa_por_linea` (`linea_activa_id`)'
            );
        }
    }

    /**
     * Nombre y fechas de la temporada global que corresponde a esa campaña.
     *
     * @return array{nombre: string, fecha_inicio: string, fecha_fin: string}
     */
    public static function temporadaDe(object $campana): array
    {
        if (preg_match('/(primavera|oto[ñn]o)\D*(\d{4})/iu', (string) $campana->nombre, $m)) {
            $anio = (int) $m[2];
            $esPrimavera = mb_strtolower($m[1]) === 'primavera';
        } else {
            $fecha = Carbon::parse($campana->fecha_inicio ?? $campana->created_at ?? now('America/Mexico_City'));
            $anio = (int) $fecha->format('Y');
            $esPrimavera = (int) $fecha->format('n') <= 6;
        }

        return $esPrimavera
            ? ['nombre' => "Primavera-Verano {$anio}", 'fecha_inicio' => "{$anio}-01-01", 'fecha_fin' => "{$anio}-06-30"]
            : ['nombre' => "Otoño-Invierno {$anio}", 'fecha_inicio' => "{$anio}-07-01", 'fecha_fin' => "{$anio}-12-31"];
    }

    private function amarrarCampanas(): void
    {
        $campanas = DB::table('campanas')->orderBy('id')->get();

        // Primero se decide todo, sin cambiar nada.
        $plan = [];
        foreach ($campanas as $campana) {
            $datos = self::temporadaDe($campana);
            $temporadaId = $this->asegurarTemporada($datos);
            $plan[$campana->linea_id.'|'.$temporadaId][] = ['campana' => $campana, 'temporada_id' => $temporadaId, 'datos' => $datos];
        }

        $sobran = [];
        foreach ($plan as $clave => $grupo) {
            if (count($grupo) === 1) {
                continue;
            }

            // Varias campañas de la misma línea caen en la misma temporada: se
            // queda la que tiene más productos; las demás tienen que estar vacías.
            usort($grupo, fn ($a, $b) => [$this->productos($b['campana']->id), $a['campana']->id] <=> [$this->productos($a['campana']->id), $b['campana']->id]);
            $plan[$clave] = [$grupo[0]];

            foreach (array_slice($grupo, 1) as $otra) {
                if ($this->estaReferenciada($otra['campana']->id)) {
                    throw new RuntimeException(
                        "La campaña {$otra['campana']->id} ({$otra['campana']->nombre}) cae en la misma temporada que la "
                        ."{$grupo[0]['campana']->id} de su línea y tiene productos o importaciones. Hay que unirlas a mano antes de migrar."
                    );
                }
                $sobran[] = $otra['campana'];
            }
        }

        DB::transaction(function () use ($plan, $sobran) {
            foreach ($sobran as $campana) {
                $this->borrar($campana, 'duplicada en la misma temporada global');
            }

            foreach ($plan as [$elegida]) {
                $campana = $elegida['campana'];
                $nuevos = [
                    'temporada_id' => $elegida['temporada_id'],
                    'nombre' => $elegida['datos']['nombre'],
                    'fecha_inicio' => $elegida['datos']['fecha_inicio'],
                    'fecha_fin' => $elegida['datos']['fecha_fin'],
                ];
                $previos = [
                    'temporada_id' => $campana->temporada_id,
                    'nombre' => $campana->nombre,
                    'fecha_inicio' => $campana->fecha_inicio,
                    'fecha_fin' => $campana->fecha_fin,
                ];

                $cambiaron = array_keys(array_filter($nuevos, fn ($valor, $campo) => (string) $previos[$campo] !== (string) $valor, ARRAY_FILTER_USE_BOTH));
                if ($cambiaron === []) {
                    continue;
                }

                DB::table('campanas')->where('id', $campana->id)->update($nuevos);
                $this->auditar('catalogo.temporada.unificada', $campana->id,
                    array_intersect_key($previos, array_flip($cambiaron)),
                    array_intersect_key($nuevos, array_flip($cambiaron)));
            }
        });
    }

    private function borrarCampanasVaciasDeRelleno(): void
    {
        $vacias = DB::table('campanas')
            ->whereNotIn('estado', ['finalizada', 'archivada'])
            ->whereNotExists(fn ($q) => $q->from('producto_campana')->whereColumn('producto_campana.campana_id', 'campanas.id'))
            ->whereNotExists(fn ($q) => $q->from('importaciones_catalogo')->whereColumn('importaciones_catalogo.campana_id', 'campanas.id'))
            ->orderBy('id')
            ->get();

        DB::transaction(function () use ($vacias) {
            foreach ($vacias as $campana) {
                $this->borrar($campana, 'campaña vacía; se vuelve a crear sola al importar');
            }
        });
    }

    private function asegurarTemporada(array $datos): int
    {
        DB::table('temporadas')->insertOrIgnore($datos + ['created_at' => now(), 'updated_at' => now()]);

        return (int) DB::table('temporadas')->where('fecha_inicio', $datos['fecha_inicio'])->value('id');
    }

    private function productos(int $campanaId): int
    {
        return DB::table('producto_campana')->where('campana_id', $campanaId)->count();
    }

    private function estaReferenciada(int $campanaId): bool
    {
        return $this->productos($campanaId) > 0
            || DB::table('importaciones_catalogo')->where('campana_id', $campanaId)->exists();
    }

    private function borrar(object $campana, string $motivo): void
    {
        if ($this->estaReferenciada($campana->id)) {
            return;
        }

        $this->auditar('catalogo.temporada.eliminada', $campana->id, (array) $campana + ['motivo' => $motivo], null);
        DB::table('campanas')->where('id', $campana->id)->delete();
    }

    private function auditar(string $accion, int $id, ?array $previos, ?array $nuevos): void
    {
        DB::table('auditorias')->insert([
            'usuario_id' => null,
            'distribuidora_id' => null,
            'accion' => $accion,
            'entidad_tipo' => 'campana',
            'entidad_id' => $id,
            'datos_previos' => $previos !== null ? json_encode($previos, JSON_UNESCAPED_UNICODE) : null,
            'datos_nuevos' => $nuevos !== null ? json_encode($nuevos, JSON_UNESCAPED_UNICODE) : null,
            'ip_origen' => null,
            'created_at' => now(),
        ]);
    }
};
