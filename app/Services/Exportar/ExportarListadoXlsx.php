<?php

namespace App\Services\Exportar;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exporta un listado del panel (pedidos, clientes, mayoristas) a .xlsx con los
 * mismos filtros que la pantalla. Quien llama ya validó permisos y tenant.
 */
class ExportarListadoXlsx
{
    /** Tope de filas por archivo: evita agotar la memoria con listados enormes. */
    public const MAX_FILAS = 10000;

    /** Por encima de esto no se autoajusta el ancho de columnas (es lento con muchas filas). */
    private const AUTOAJUSTE_HASTA = 2000;

    /**
     * La acción de Livewire no vuelve a pasar por el middleware de la ruta, así
     * que se repite aquí la regla de la pantalla: personal activo de la
     * distribuidora actual (y, si se pide, administrador).
     */
    public static function exigirPersonal(bool $soloAdministrador = false): void
    {
        $usuario = auth()->user();
        abort_if($usuario === null || \App\Support\Tenant::id() === null, 403);

        $staff = \App\Models\DistribuidoraStaff::query()
            ->where('usuario_id', $usuario->id)
            ->where('estado', 'activo')
            ->when($soloAdministrador, fn ($q) => $q->where('tipo', 'administrador'))
            ->exists();

        abort_unless($staff, 403, \App\Support\MensajeError::SIN_PERMISO);
    }

    /**
     * @param  array<int, string>  $encabezados
     * @param  iterable<array<int, scalar|null>>  $filas
     */
    public function libro(string $titulo, array $encabezados, iterable $filas, array $filtros = []): Spreadsheet
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle(mb_substr($titulo, 0, 31));

        $fila = 1;
        $hoja->setCellValue('A1', $titulo);
        $hoja->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $fila++;
        $hoja->setCellValue('A'.$fila, 'Generado: '.now()->format('d/m/Y H:i'));
        $fila++;
        foreach ($filtros as $nombre => $valor) {
            $hoja->setCellValue('A'.$fila, $nombre.': '.$valor);
            $fila++;
        }
        $fila++;

        $ultima = Coordinate::stringFromColumnIndex(count($encabezados));
        $hoja->fromArray($encabezados, null, 'A'.$fila);
        $hoja->getStyle("A{$fila}:{$ultima}{$fila}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $hoja->getStyle("A{$fila}:{$ultima}{$fila}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1E2F52');
        $hoja->freezePane('A'.($fila + 1));

        $escritas = 0;
        foreach ($filas as $datos) {
            if ($escritas >= self::MAX_FILAS) {
                $fila += 2;
                $hoja->setCellValue('A'.$fila, 'Se exportaron las primeras '.number_format(self::MAX_FILAS).' filas. Usa los filtros para acotar el listado.');
                break;
            }
            $fila++;
            $escritas++;
            $hoja->fromArray(array_values($datos), null, 'A'.$fila, true);
        }

        foreach (range(1, count($encabezados)) as $i) {
            $columna = $hoja->getColumnDimension(Coordinate::stringFromColumnIndex($i));
            $escritas <= self::AUTOAJUSTE_HASTA ? $columna->setAutoSize(true) : $columna->setWidth(22);
        }

        return $libro;
    }

    /** El mismo libro como texto binario, para guardarlo en disco (exportación en cola). */
    public function contenido(string $titulo, array $encabezados, iterable $filas, array $filtros = []): string
    {
        $libro = $this->libro($titulo, $encabezados, $filas, $filtros);
        $escritor = new Xlsx($libro);
        $escritor->setPreCalculateFormulas(false);

        ob_start();
        try {
            $escritor->save('php://output');
        } finally {
            $contenido = (string) ob_get_clean();
            $libro->disconnectWorksheets();
        }

        return $contenido;
    }

    public function descargar(string $archivo, string $titulo, array $encabezados, iterable $filas, array $filtros = []): StreamedResponse
    {
        $libro = $this->libro($titulo, $encabezados, $filas, $filtros);

        return response()->streamDownload(function () use ($libro) {
            $escritor = new Xlsx($libro);
            $escritor->setPreCalculateFormulas(false);
            $escritor->save('php://output');
            $libro->disconnectWorksheets();
        }, $archivo, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
