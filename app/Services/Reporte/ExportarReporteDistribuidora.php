<?php

namespace App\Services\Reporte;

use App\Models\CicloCompra;
use App\Models\Distribuidora;
use App\Support\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Descarga del reporte de la distribuidora en PDF o Excel con los mismos
 * filtros que hay en pantalla. Las dos salidas se arman de las mismas
 * secciones, que salen de las acciones que ya usa la pantalla.
 */
class ExportarReporteDistribuidora
{
    public const FORMATOS = ['pdf', 'xlsx'];

    /**
     * @param  array{desde?: ?string, hasta?: ?string, estado?: ?string, ciclo?: ?int, temporada?: ?int, marca?: ?int}  $f
     * @return list<array{titulo: string, encabezados: list<string>, filas: list<list<string|int|float>>}>
     */
    public function secciones(array $f): array
    {
        $desde = $f['desde'] ?? null;
        $hasta = $f['hasta'] ?? null;
        $estado = $f['estado'] ?? null;
        $ciclo = $f['ciclo'] ?? null;

        $cuenta = app(EstadoDeCuentaAction::class)->ejecutar($desde, $hasta, $estado, $ciclo);
        $resumen = app(ResumenOperativoAction::class)->ejecutar($desde, $hasta, $estado, $ciclo);
        $mayoristas = app(PedidosPorRevendedorAction::class)->ejecutar($desde, $hasta, $ciclo);
        $pendientes = app(MontosPendientesAction::class)->ejecutar($ciclo);
        $demanda = app(DemandaPorTemporadaMarcaAction::class)->ejecutar($f['temporada'] ?? null, $f['marca'] ?? null, $desde, $hasta, $ciclo);

        $secciones = $this->armarSecciones($f, $cuenta, $resumen, $mayoristas, $pendientes, $demanda);

        // Ninguna celda puede llegar al PDF/Excel como arreglo u objeto.
        return array_map(function (array $s) {
            $s['filas'] = array_map(fn ($fila) => array_map(fn ($valor) => self::celda($valor), array_values((array) $fila)), $s['filas']);

            return $s;
        }, $secciones);
    }

    private function armarSecciones(array $f, array $cuenta, array $resumen, array $mayoristas, array $pendientes, array $demanda): array
    {
        $clientes = fn (array $grupo) => array_map(fn ($r) => [$r['nombre'], $r['pedidos'], $r['monto'], $r['pagado'], $r['pendiente']], $grupo['filas']);

        return [
            ['titulo' => 'Estado de cuenta', 'encabezados' => ['Concepto', 'Monto'], 'filas' => [
                ['Saldo anterior', $cuenta['saldo_anterior']], ['Abonos', $cuenta['abonos']], ['Cargos', $cuenta['cargos']], ['Saldo', $cuenta['saldo']],
            ]],
            ['titulo' => 'Movimientos', 'encabezados' => ['Fecha', 'Concepto', 'Folio', 'Cargo', 'Abono', 'Saldo'],
                'filas' => array_map(fn ($m) => [$m['fecha'], $m['concepto'], $m['folio'], $m['cargo'], $m['abono'], $m['saldo']], $cuenta['movimientos'])],
            ['titulo' => 'Pedidos por estado', 'encabezados' => ['Estado', 'Pedidos', 'Monto'],
                'filas' => array_map(fn ($r) => [str_replace('_', ' ', $r['estado']), $r['cantidad'], $r['monto']], array_values($resumen['pedidos']['por_estado']))],
            ['titulo' => 'Pedidos', 'encabezados' => ['Folio', 'Fecha', 'Estado', 'Cliente', 'Tipo', 'Capturado por', 'Total'],
                'filas' => array_map(fn ($p) => [$p['folio'], $p['fecha'] ?? '—', str_replace('_', ' ', $p['estado']), $p['quien'] ?? '—', $p['tipo'], $p['capturado_por'] ?? '—', $p['total']], array_values($resumen['pedidos']['lista']))],
            ['titulo' => 'Ventas directas', 'encabezados' => ['Folio', 'Fecha', 'Cliente', 'Sucursal', 'Estado', 'Total'],
                'filas' => array_map(fn ($v) => [$v['folio'], $v['fecha'] ?? '—', $v['quien'], $v['sucursal'] ?? '—', $v['estado'], $v['total']], array_values($resumen['ventas_directas']['lista']))],
            ['titulo' => 'Por cliente mayorista', 'encabezados' => ['Cliente', 'Pedidos', 'Monto', 'Pagado', 'Pendiente'], 'filas' => $clientes($mayoristas)],
            ['titulo' => 'Por cobrar: clientes directos', 'encabezados' => ['Cliente', 'Pedidos', 'Monto', 'Pagado', 'Pendiente'], 'filas' => $clientes($pendientes['clientes_directos'])],
            ['titulo' => 'Por cobrar: clientes mayoristas', 'encabezados' => ['Cliente', 'Pedidos', 'Monto', 'Pagado', 'Pendiente'], 'filas' => $clientes($pendientes['revendedores'])],
            ['titulo' => 'Vales vigentes', 'encabezados' => ['Folio', 'Propietario', 'Saldo', 'Vence'],
                'filas' => array_map(fn ($v) => [$v['folio'], $this->propietario($v['propietario'] ?? null), $v['saldo'], $v['fecha_vencimiento'] ?? '—'], $pendientes['vales_vigentes']['filas'])],
            ['titulo' => 'Demanda por marca', 'encabezados' => ['Marca', 'Piezas', 'Pedidos', 'Modelos', 'Monto'],
                'filas' => array_map(fn ($r) => [$r['nombre'], $r['piezas'], $r['pedidos'], $r['modelos'], $r['monto']], $demanda['por_marca'])],
            ['titulo' => 'Demanda por producto', 'encabezados' => ['Producto', 'Modelo', 'Marca', 'Temporada', 'Piezas', 'Pedidos', 'Monto'],
                'filas' => array_map(fn ($r) => [$r['producto'], $r['modelo'], $r['marca'], $r['temporada'], $r['piezas'], $r['pedidos'], $r['monto']], $demanda['productos'])],
        ];
    }

    /** El propietario de un vale llega como ['tipo' => ..., 'nombre' => ...]. */
    private function propietario(mixed $p): string
    {
        if (! is_array($p)) {
            return self::celda($p);
        }
        $nombre = trim((string) ($p['nombre'] ?? '')) ?: '—';
        $tipo = match ($p['tipo'] ?? null) {
            'cliente_directo' => 'Cliente',
            'revendedor' => 'Cliente mayorista',
            default => null,
        };

        return $tipo ? $nombre.' ('.$tipo.')' : $nombre;
    }

    /** Convierte cualquier valor en algo imprimible: texto, entero o flotante. */
    public static function celda(mixed $valor): string|int|float
    {
        return match (true) {
            $valor === null => '—',
            is_int($valor), is_float($valor), is_string($valor) => $valor,
            is_bool($valor) => $valor ? 'Sí' : 'No',
            $valor instanceof \BackedEnum => (string) $valor->value,
            $valor instanceof \UnitEnum => $valor->name,
            $valor instanceof \DateTimeInterface => $valor->format('d/m/Y'),
            $valor instanceof \Stringable => (string) $valor,
            is_array($valor) => isset($valor['nombre']) && ! is_array($valor['nombre'])
                ? (string) $valor['nombre']
                : implode(', ', array_map(fn ($v) => (string) self::celda($v), array_values($valor))),
            default => '—',
        };
    }

    /** Texto del periodo y filtros, para el encabezado de los archivos. */
    public function descripcionFiltros(array $f): string
    {
        $partes = [];
        $fecha = fn (string $d) => Carbon::parse($d)->format('d/m/Y');
        if (! empty($f['desde']) || ! empty($f['hasta'])) {
            $partes[] = 'Periodo: '.(! empty($f['desde']) ? $fecha($f['desde']) : 'inicio').' al '.(! empty($f['hasta']) ? $fecha($f['hasta']) : 'hoy');
        } else {
            $partes[] = 'Todos los movimientos';
        }
        if (! empty($f['ciclo'])) {
            $partes[] = 'Ciclo: '.(CicloCompra::query()->whereKey($f['ciclo'])->value('nombre') ?? '#'.$f['ciclo']);
        }
        if (! empty($f['estado'])) {
            $partes[] = 'Estado: '.str_replace('_', ' ', $f['estado']);
        }

        return implode(' · ', $partes);
    }

    /**
     * Estimación barata (dos COUNT) del tamaño del reporte, para decidir si se
     * descarga al momento o va a la cola: pedidos del periodo + movimientos
     * del estado de cuenta (pagos), que son las secciones que crecen.
     */
    public function filasEstimadas(array $f): int
    {
        $periodo = function ($q, string $columna) use ($f) {
            return $q->when($f['desde'] ?? null, fn ($q, $d) => $q->whereDate($columna, '>=', $d))
                ->when($f['hasta'] ?? null, fn ($q, $h) => $q->whereDate($columna, '<=', $h));
        };

        $pedidos = $periodo(\App\Models\Pedido::query(), 'created_at')
            ->when($f['estado'] ?? null, fn ($q, $e) => $q->where('estado', $e))
            ->when($f['ciclo'] ?? null, fn ($q, $c) => $q->where('ciclo_compra_id', $c))
            ->count();

        return $pedidos + \App\Models\Pago::query()->count();
    }

    public function nombreArchivo(string $formato): string
    {
        $slug = Distribuidora::query()->whereKey(Tenant::id())->value('slug') ?? 'distribuidora';

        return 'reporte-'.$slug.'-'.now()->format('Y-m-d').'.'.$formato;
    }

    public function pdf(array $f): string
    {
        return Pdf::loadView('reportes.pdf', [
            'casa' => Distribuidora::query()->findOrFail(Tenant::id()),
            'filtros' => $this->descripcionFiltros($f),
            'secciones' => $this->secciones($f),
        ])->setPaper('letter', 'landscape')->output();
    }

    public function xlsx(array $f): string
    {
        $libro = new Spreadsheet;
        $libro->getProperties()->setCreator('FootwearPoint')->setTitle('Reporte de la distribuidora');
        $libro->removeSheetByIndex(0);
        $casa = Distribuidora::query()->findOrFail(Tenant::id());

        foreach ($this->secciones($f) as $i => $seccion) {
            $hoja = $libro->createSheet($i);
            // Excel limita el nombre de la hoja a 31 caracteres y prohíbe : \ / ? * [ ]
            $hoja->setTitle(mb_substr(str_replace([':', '\\', '/', '?', '*', '[', ']'], '', $seccion['titulo']), 0, 31));
            $hoja->fromArray([[$casa->nombre_comercial.' — '.$seccion['titulo']], [$this->descripcionFiltros($f)], []], null, 'A1');
            $hoja->getStyle('A1')->getFont()->setBold(true)->setSize(13);
            $hoja->fromArray($seccion['encabezados'], null, 'A4');
            $ultima = chr(ord('A') + count($seccion['encabezados']) - 1);
            $hoja->getStyle("A4:{$ultima}4")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $hoja->getStyle("A4:{$ultima}4")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('111E38');
            if ($seccion['filas'] !== []) {
                $hoja->fromArray($seccion['filas'], null, 'A5', true);
            } else {
                $hoja->setCellValue('A5', 'Sin datos con estos filtros.');
            }
            foreach (range('A', $ultima) as $col) {
                $hoja->getColumnDimension($col)->setAutoSize(true);
            }
        }
        $libro->setActiveSheetIndex(0);

        ob_start();
        (new Xlsx($libro))->save('php://output');

        return (string) ob_get_clean();
    }
}
