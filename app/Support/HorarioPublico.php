<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Horario público estructurado de una distribuidora.
 *
 * Se guarda en `horarios_publicos` (JSON) y se exhibe como texto en
 * `horario_publico`, que es lo que ya muestran el marketplace, la tienda y la API.
 *
 * Cada grupo tiene días (lun…dom), uno o más rangos de horas, o bien
 * "cerrado". Un día solo puede estar en un grupo y los rangos de un mismo
 * día no pueden traslaparse.
 */
class HorarioPublico
{
    public const DIAS = ['lun', 'mar', 'mie', 'jue', 'vie', 'sab', 'dom'];

    public const CHIPS = [
        'lun' => 'L',
        'mar' => 'M',
        'mie' => 'X',
        'jue' => 'J',
        'vie' => 'V',
        'sab' => 'S',
        'dom' => 'D',
    ];

    public const ETIQUETAS = [
        'lun' => 'Lun',
        'mar' => 'Mar',
        'mie' => 'Mié',
        'jue' => 'Jue',
        'vie' => 'Vie',
        'sab' => 'Sáb',
        'dom' => 'Dom',
    ];

    public const MAX_TEXTO = 300;

    /**
     * @return array<string, array{nombre: string, grupos: list<array<string, mixed>>}>
     */
    public static function plantillas(): array
    {
        return [
            'lv_9_18' => [
                'nombre' => 'L–V 9:00–18:00',
                'grupos' => [
                    self::grupo(['lun', 'mar', 'mie', 'jue', 'vie'], [['09:00', '18:00']]),
                    self::grupo(['sab', 'dom'], [], true),
                ],
            ],
            'ls_9_19' => [
                'nombre' => 'L–S 9:00–19:00',
                'grupos' => [
                    self::grupo(['lun', 'mar', 'mie', 'jue', 'vie', 'sab'], [['09:00', '19:00']]),
                    self::grupo(['dom'], [], true),
                ],
            ],
            '24_horas' => [
                'nombre' => '24 horas',
                'grupos' => [
                    self::grupo(self::DIAS, [['00:00', '23:59']]),
                ],
            ],
        ];
    }

    /**
     * Normaliza, valida y prepara lo que se va a guardar.
     *
     * Si no hay grupos con días, se conserva el texto libre (horario legado)
     * y el JSON queda en null.
     *
     * @param  list<array<string, mixed>>|null  $grupos
     * @return array{horarios_publicos: ?array, horario_publico: ?string}
     *
     * @throws ValidationException
     */
    public static function preparar(?array $grupos, ?string $textoLibre = null): array
    {
        $grupos = self::normalizar($grupos);
        $errores = self::validar($grupos);

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        if ($grupos === []) {
            $texto = trim((string) $textoLibre);

            return [
                'horarios_publicos' => null,
                'horario_publico' => $texto === '' ? null : $texto,
            ];
        }

        $texto = self::formatear($grupos);

        if ($texto !== null && mb_strlen($texto) > self::MAX_TEXTO) {
            throw ValidationException::withMessages([
                'horario_publico' => ['El horario público no puede pasar de 300 caracteres. Quita algún rango o grupo.'],
            ]);
        }

        return [
            'horarios_publicos' => $grupos,
            'horario_publico' => $texto,
        ];
    }

    /**
     * @param  list<array<string, mixed>>|null  $grupos
     * @return list<array{dias: list<string>, rangos: list<array{0: string, 1: string}>, cerrado: bool}>
     */
    public static function normalizar(?array $grupos): array
    {
        if (! is_array($grupos)) {
            return [];
        }

        $limpios = [];

        foreach ($grupos as $grupo) {
            if (! is_array($grupo)) {
                continue;
            }

            $dias = [];
            foreach ($grupo['dias'] ?? [] as $dia) {
                $dia = is_string($dia) ? strtolower(trim($dia)) : '';
                if (in_array($dia, self::DIAS, true) && ! in_array($dia, $dias, true)) {
                    $dias[] = $dia;
                }
            }
            $dias = self::ordenarDias($dias);

            $cerrado = (bool) ($grupo['cerrado'] ?? false);
            $rangos = [];

            if (! $cerrado) {
                foreach ($grupo['rangos'] ?? [] as $rango) {
                    $inicio = self::normalizarHora(is_array($rango) ? ($rango[0] ?? $rango['inicio'] ?? null) : null);
                    $fin = self::normalizarHora(is_array($rango) ? ($rango[1] ?? $rango['fin'] ?? null) : null);
                    if ($inicio === null && $fin === null) {
                        continue;
                    }
                    $rangos[] = [$inicio ?? '', $fin ?? ''];
                }
            }

            if ($dias === [] && $rangos === [] && ! $cerrado) {
                continue;
            }

            $limpios[] = [
                'dias' => $dias,
                'rangos' => $rangos,
                'cerrado' => $cerrado,
            ];
        }

        return $limpios;
    }

    /**
     * @param  list<array<string, mixed>>  $grupos  ya normalizados o crudos
     * @return array<string, list<string>>
     */
    public static function validar(?array $grupos): array
    {
        $grupos = self::normalizar($grupos);
        $errores = [];
        $diasUsados = [];

        foreach ($grupos as $indice => $grupo) {
            $n = $indice + 1;

            if ($grupo['dias'] === []) {
                $errores['horario_publico'][] = "El horario {$n} necesita al menos un día.";

                continue;
            }

            foreach ($grupo['dias'] as $dia) {
                if (isset($diasUsados[$dia])) {
                    $etiqueta = self::ETIQUETAS[$dia];
                    $errores['horario_publico'][] = "{$etiqueta} ya está en otro horario. Un día no puede estar en dos grupos.";
                }
                $diasUsados[$dia] = $n;
            }

            if ($grupo['cerrado']) {
                continue;
            }

            if ($grupo['rangos'] === []) {
                $errores['horario_publico'][] = "El horario {$n} necesita al menos un rango de horas, o márcalo como cerrado.";

                continue;
            }

            $minutos = [];
            foreach ($grupo['rangos'] as $r => $rango) {
                $inicio = self::aMinutos($rango[0]);
                $fin = self::aMinutos($rango[1]);

                if ($inicio === null || $fin === null) {
                    $errores['horario_publico'][] = "El horario {$n} tiene una hora incompleta o inválida.";

                    continue;
                }

                if ($fin <= $inicio) {
                    $errores['horario_publico'][] = "En el horario {$n}, la hora de fin debe ser mayor que la de inicio.";

                    continue;
                }

                $minutos[] = [$inicio, $fin, $r];
            }

            usort($minutos, fn ($a, $b) => $a[0] <=> $b[0]);
            for ($i = 1, $total = count($minutos); $i < $total; $i++) {
                if ($minutos[$i][0] < $minutos[$i - 1][1]) {
                    $errores['horario_publico'][] = "El horario {$n} tiene rangos que se traslapan.";
                    break;
                }
            }
        }

        return $errores;
    }

    /**
     * @param  list<array<string, mixed>>|null  $grupos
     */
    public static function formatear(?array $grupos): ?string
    {
        $grupos = self::normalizar($grupos);

        if ($grupos === []) {
            return null;
        }

        $partes = [];
        foreach ($grupos as $grupo) {
            if ($grupo['dias'] === []) {
                continue;
            }

            $dias = self::formatearDias($grupo['dias']);

            if ($grupo['cerrado']) {
                $partes[] = $dias.' cerrado';

                continue;
            }

            if (self::esVeinticuatroHoras($grupo['rangos'])) {
                $partes[] = $dias.', las 24 horas';

                continue;
            }

            $horas = [];
            foreach ($grupo['rangos'] as $rango) {
                if ($rango[0] === '' || $rango[1] === '') {
                    continue;
                }
                $horas[] = self::formatearHora($rango[0]).'–'.self::formatearHora($rango[1]);
            }

            if ($horas === []) {
                continue;
            }

            $partes[] = $dias.' '.self::unirLista($horas);
        }

        $texto = implode(' · ', $partes);

        return $texto === '' ? null : $texto;
    }

    /**
     * Intenta reconocer frases comunes del texto libre. Si no puede, null.
     *
     * @return list<array{dias: list<string>, rangos: list<array{0: string, 1: string}>, cerrado: bool}>|null
     */
    public static function intentarParsear(?string $texto): ?array
    {
        $texto = trim((string) $texto);
        if ($texto === '') {
            return null;
        }

        $normal = mb_strtolower($texto);
        $normal = strtr($normal, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            '–' => '-', '—' => '-', ' a ' => '-', ' y ' => ' y ',
        ]);

        if (! preg_match('/(\d{1,2})(?::(\d{2}))?\s*-\s*(\d{1,2})(?::(\d{2}))?/', $normal, $hora)) {
            return null;
        }

        $inicio = sprintf('%02d:%02d', (int) $hora[1], (int) ($hora[2] !== '' ? $hora[2] : 0));
        $fin = sprintf('%02d:%02d', (int) $hora[3], (int) ($hora[4] !== '' ? $hora[4] : 0));

        $mapa = [
            'lunes' => 'lun', 'lun' => 'lun',
            'martes' => 'mar', 'mar' => 'mar',
            'miercoles' => 'mie', 'mie' => 'mie', 'x' => 'mie',
            'jueves' => 'jue', 'jue' => 'jue',
            'viernes' => 'vie', 'vie' => 'vie',
            'sabado' => 'sab', 'sab' => 'sab',
            'domingo' => 'dom', 'dom' => 'dom',
        ];

        $dias = [];
        if (preg_match('/(lunes|lun|l)\s*(?:a|-|–)\s*(domingo|dom|sabado|sab|viernes|vie|jueves|jue|miercoles|mie)/u', $normal, $rangoDias)) {
            $desde = $mapa[$rangoDias[1]] ?? ($rangoDias[1] === 'l' ? 'lun' : null);
            $hastaClave = $rangoDias[2];
            $hasta = $mapa[$hastaClave] ?? null;
            if ($desde && $hasta) {
                $dias = self::diasEntre($desde, $hasta);
            }
        }

        if ($dias === []) {
            return null;
        }

        $grupos = [self::grupo($dias, [[$inicio, $fin]])];
        $restantes = array_values(array_diff(self::DIAS, $dias));
        if ($restantes !== []) {
            $grupos[] = self::grupo($restantes, [], true);
        }

        $errores = self::validar($grupos);

        return $errores === [] ? self::normalizar($grupos) : null;
    }

    public static function grupoVacio(): array
    {
        return self::grupo([], [['09:00', '18:00']], false);
    }

    /**
     * @param  list<string>  $dias
     * @param  list<array{0: string, 1: string}>  $rangos
     * @return array{dias: list<string>, rangos: list<array{0: string, 1: string}>, cerrado: bool}
     */
    public static function grupo(array $dias, array $rangos = [], bool $cerrado = false): array
    {
        return [
            'dias' => self::ordenarDias($dias),
            'rangos' => $cerrado ? [] : $rangos,
            'cerrado' => $cerrado,
        ];
    }

    /** @param  list<string>  $dias
     *  @return list<string>
     */
    private static function ordenarDias(array $dias): array
    {
        $orden = array_flip(self::DIAS);
        usort($dias, fn ($a, $b) => ($orden[$a] ?? 99) <=> ($orden[$b] ?? 99));

        return array_values($dias);
    }

    /** @param  list<string>  $dias */
    private static function formatearDias(array $dias): string
    {
        $dias = self::ordenarDias($dias);
        if ($dias === []) {
            return '';
        }

        $etiquetas = array_map(fn ($d) => self::ETIQUETAS[$d], $dias);

        if (count($dias) === 1) {
            return $etiquetas[0];
        }

        if (self::sonConsecutivos($dias)) {
            return $etiquetas[0].'–'.$etiquetas[array_key_last($etiquetas)];
        }

        return self::unirLista($etiquetas);
    }

    /** @param  list<string>  $dias */
    private static function sonConsecutivos(array $dias): bool
    {
        $orden = array_flip(self::DIAS);
        for ($i = 1, $n = count($dias); $i < $n; $i++) {
            if (($orden[$dias[$i]] ?? -99) !== ($orden[$dias[$i - 1]] ?? -99) + 1) {
                return false;
            }
        }

        return true;
    }

    /** @param  list<string>  $partes */
    private static function unirLista(array $partes): string
    {
        if (count($partes) <= 1) {
            return $partes[0] ?? '';
        }

        $ultimo = array_pop($partes);

        return implode(', ', $partes).' y '.$ultimo;
    }

    private static function formatearHora(string $hora): string
    {
        if (! preg_match('/^(\d{2}):(\d{2})$/', $hora, $m)) {
            return $hora;
        }

        return ((int) $m[1]).':'.$m[2];
    }

    private static function normalizarHora(mixed $valor): ?string
    {
        $valor = trim((string) $valor);
        if ($valor === '') {
            return null;
        }

        if (preg_match('/^(\d{1,2}):(\d{2})$/', $valor, $m)) {
            $h = (int) $m[1];
            $min = (int) $m[2];
            if ($h >= 0 && $h <= 23 && $min >= 0 && $min <= 59) {
                return sprintf('%02d:%02d', $h, $min);
            }
        }

        return null;
    }

    private static function aMinutos(?string $hora): ?int
    {
        $hora = self::normalizarHora($hora);
        if ($hora === null) {
            return null;
        }

        [$h, $m] = array_map('intval', explode(':', $hora));

        return $h * 60 + $m;
    }

    /** @param  list<array{0: string, 1: string}>  $rangos */
    private static function esVeinticuatroHoras(array $rangos): bool
    {
        return count($rangos) === 1 && $rangos[0][0] === '00:00' && $rangos[0][1] === '23:59';
    }

    /** @return list<string> */
    private static function diasEntre(string $desde, string $hasta): array
    {
        $i = array_search($desde, self::DIAS, true);
        $j = array_search($hasta, self::DIAS, true);
        if ($i === false || $j === false || $j < $i) {
            return [];
        }

        return array_slice(self::DIAS, $i, $j - $i + 1);
    }
}
