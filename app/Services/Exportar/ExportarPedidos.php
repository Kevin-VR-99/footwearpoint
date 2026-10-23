<?php

namespace App\Services\Exportar;

use App\Models\Pedido;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Listado de pedidos en Excel. Lo usan la pantalla de pedidos (descarga
 * inmediata) y el job GenerarExportacion (listados grandes), así que la
 * consulta y las columnas son las mismas en los dos caminos.
 *
 * @phpstan-type Filtros array{estado?: ?string, tipo?: ?string}
 */
class ExportarPedidos
{
    public const TIPO = 'pedidos';

    /** @param  Filtros  $f */
    public function consulta(array $f): Builder
    {
        return Pedido::query()
            ->with(['clienteDirecto', 'revendedorAfiliacion.revendedor'])
            ->orderByDesc('id')
            ->when(($f['estado'] ?? '') !== '', fn ($q) => $q->where('estado', $f['estado']))
            ->when(($f['tipo'] ?? '') !== '', fn ($q) => $q->where('tipo', $f['tipo']))
            ->when(trim($f['buscar'] ?? '') !== '', function ($q) use ($f) {
                $t = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim($f['buscar'])).'%';
                $q->where(fn ($w) => $w->where('folio', 'like', $t)
                    ->orWhereHas('clienteDirecto', fn ($c) => $c->where('nombre', 'like', $t))
                    ->orWhereHas('revendedorAfiliacion.revendedor', fn ($r) => $r->where('nombre', 'like', $t)));
            });
    }

    public function total(array $f): int
    {
        return $this->consulta($f)->toBase()->getCountForPagination();
    }

    public function descargar(array $f): StreamedResponse
    {
        return app(ExportarListadoXlsx::class)->descargar($this->nombreArchivo(), 'Pedidos', $this->encabezados(), $this->filas($f), $this->filtrosLegibles($f));
    }

    public function contenido(array $f): string
    {
        return app(ExportarListadoXlsx::class)->contenido('Pedidos', $this->encabezados(), $this->filas($f), $this->filtrosLegibles($f));
    }

    public function nombreArchivo(): string
    {
        return 'pedidos-'.now()->format('Ymd-His').'.xlsx';
    }

    private function encabezados(): array
    {
        return ['Folio', 'Tipo', 'Propietario', 'Estado', 'Fecha', 'Total'];
    }

    private function filas(array $f): iterable
    {
        // lazy(): de 1000 en 1000, para que un listado grande no cargue todo en
        // memoria (lazy ignora limit, por eso el tope va con take()).
        return $this->consulta($f)->lazy(1000)->take(ExportarListadoXlsx::MAX_FILAS + 1)->map(fn (Pedido $p) => [
            $p->folio,
            $p->tipo === 'cliente_directo' ? 'Cliente' : 'Cliente mayorista',
            $p->clienteDirecto?->nombre ?? $p->revendedorAfiliacion?->revendedor?->nombre ?? '—',
            ucfirst(str_replace('_', ' ', (string) $p->estado)),
            $p->fecha_colocacion?->format('d/m/Y H:i') ?? $p->created_at?->format('d/m/Y H:i'),
            (float) $p->total,
        ]);
    }

    private function filtrosLegibles(array $f): array
    {
        return array_filter([
            'Estado' => ($f['estado'] ?? '') !== '' ? ucfirst(str_replace('_', ' ', $f['estado'])) : null,
            'Tipo' => match ($f['tipo'] ?? '') { 'cliente_directo' => 'Cliente directo', 'revendedor' => 'Cliente mayorista', default => null },
        ]);
    }
}
