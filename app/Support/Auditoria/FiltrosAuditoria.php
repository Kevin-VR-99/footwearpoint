<?php

namespace App\Support\Auditoria;

use App\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Livewire\WithPagination;

/**
 * TG-267 (U7): filtros y consulta de las dos pantallas de auditoría, la de la
 * distribuidora y la del admin general. Cada una decide qué registros le
 * tocan con consultaBase().
 */
trait FiltrosAuditoria
{
    use WithPagination;

    public string $filtro_area = '';
    public string $filtro_accion = '';
    public string $filtro_entidad = '';
    public string $filtro_usuario = '';
    public string $filtro_desde = '';
    public string $filtro_hasta = '';
    public string $buscar = '';
    public ?int $detalleId = null;

    abstract protected function consultaBase(): Builder;

    public function updated(string $propiedad): void
    {
        if (str_starts_with($propiedad, 'filtro_') || $propiedad === 'buscar') {
            $this->resetPage();
        }
    }

    public function limpiarFiltros(): void
    {
        $this->filtro_area = '';
        $this->filtro_accion = '';
        $this->filtro_entidad = '';
        $this->filtro_usuario = '';
        $this->filtro_desde = '';
        $this->filtro_hasta = '';
        $this->buscar = '';
        $this->resetPage();
    }

    public function toggleDetalle(int $id): void
    {
        $this->detalleId = $this->detalleId === $id ? null : $id;
    }

    public function getAccionesProperty()
    {
        return $this->consultaBase()->select('accion')->distinct()->orderBy('accion')->pluck('accion');
    }

    public function getEntidadesProperty()
    {
        return $this->consultaBase()->select('entidad_tipo')->distinct()->orderBy('entidad_tipo')->pluck('entidad_tipo');
    }

    public function getAreasProperty()
    {
        return EtiquetasAuditoria::areasDe($this->acciones);
    }

    public function getUsuariosProperty()
    {
        return Usuario::query()
            ->whereIn('id', $this->consultaBase()->whereNotNull('usuario_id')->select('usuario_id'))
            ->orderBy('email')
            ->get();
    }

    public function getRegistrosProperty()
    {
        $query = $this->consultaBase()
            ->with('usuario')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($this->filtro_area !== '') {
            $prefijos = EtiquetasAuditoria::prefijosDe($this->filtro_area);
            $query->where(function ($q) use ($prefijos) {
                $q->whereRaw('1 = 0');
                foreach ($prefijos as $prefijo) {
                    $q->orWhere('accion', 'like', $prefijo.'.%');
                }
            });
        }

        if ($this->filtro_accion !== '') {
            $query->where('accion', $this->filtro_accion);
        }

        if ($this->filtro_entidad !== '') {
            $query->where('entidad_tipo', $this->filtro_entidad);
        }

        if ($this->filtro_usuario !== '') {
            $query->where('usuario_id', (int) $this->filtro_usuario);
        }

        if ($this->filtro_desde !== '') {
            $query->whereDate('created_at', '>=', $this->filtro_desde);
        }

        if ($this->filtro_hasta !== '') {
            $query->whereDate('created_at', '<=', $this->filtro_hasta);
        }

        if ($this->buscar !== '') {
            $termino = '%'.$this->buscar.'%';
            $query->where(function ($q) use ($termino) {
                $q->where('accion', 'like', $termino)
                    ->orWhere('entidad_tipo', 'like', $termino)
                    ->orWhere('ip_origen', 'like', $termino)
                    ->orWhereHas('usuario', fn ($u) => $u->where('nombre', 'like', $termino)
                        ->orWhere('email', 'like', $termino));
            });
        }

        return $query->paginate(20);
    }
}
