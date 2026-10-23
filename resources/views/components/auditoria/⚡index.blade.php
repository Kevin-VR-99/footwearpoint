<?php

use App\Models\Auditoria;
use App\Support\Auditoria\FiltrosAuditoria;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.panel')] #[Title('Auditoría — FootwearPoint')] class extends Component {
    use FiltrosAuditoria;

    public function mount()
    {
        if (! Auth::check()) {
            return $this->redirect(route('login'), navigate: true);
        }

        if (Tenant::id() === null) {
            abort(403, 'No se pudo determinar la distribuidora.');
        }

        if (! Auth::user()->hasRole('admin_distribuidora')) {
            abort(403, 'Solo el administrador de la distribuidora puede ver la auditoría.');
        }
    }

    /** Solo los de esta distribuidora: el TenantScope de Auditoria ya filtra. */
    protected function consultaBase(): Builder
    {
        return Auditoria::query();
    }
};
?>

<div>
    <x-panel.encabezado eyebrow="Seguridad" titulo="Auditoría"
        subtitulo="Registro de operaciones sensibles de la distribuidora (E17-03)." class="mb-6">
        <x-slot:acciones>
        <span class="inline-flex items-center gap-1.5 self-start rounded-full border border-fp-danger/20 bg-fp-danger-soft px-2.5 py-1 text-[11px] font-medium text-fp-badge-danger-fg">
            <span class="h-1.5 w-1.5 rounded-full bg-fp-danger"></span>
            Solo admin
        </span>
        </x-slot:acciones>
    </x-panel.encabezado>

    <x-auditoria.lista :registros="$this->registros" :areas="$this->areas" :acciones="$this->acciones"
        :entidades="$this->entidades" :usuarios="$this->usuarios" :detalle-id="$detalleId" />
</div>
