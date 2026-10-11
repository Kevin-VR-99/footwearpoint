<?php

use App\Models\Auditoria;
use App\Support\Auditoria\FiltrosAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| TG-267 (U7, E17-03) - Auditoría del admin general (solo lectura).
|
| Muestra lo que no es de ninguna distribuidora: los cambios al catálogo
| compartido y sus importaciones, que hace FootwearPoint. Lo de cada
| distribuidora se queda en su propia pantalla (/auditoria).
*/
new #[Layout('layouts.admin')] #[Title('Auditoría — Admin')] class extends Component
{
    use FiltrosAuditoria;

    public function mount()
    {
        if (! Auth::check()) {
            return $this->redirect(route('login'), navigate: true);
        }

        setPermissionsTeamId(0);

        if (! Auth::user()->hasRole('admin_general')) {
            abort(403, 'Solo admin general.');
        }
    }

    /**
     * Sin el filtro por distribuidora a propósito: estos registros no tienen
     * distribuidora (distribuidora_id null) y solo los ve el admin general.
     */
    protected function consultaBase(): Builder
    {
        return Auditoria::withoutGlobalScopes()->whereNull('distribuidora_id');
    }
};
?>

<div>
    <x-panel.encabezado eyebrow="Seguridad" titulo="Auditoría"
        subtitulo="Cambios al catálogo compartido y otras operaciones de FootwearPoint." class="mb-6" />

    <x-auditoria.lista :registros="$this->registros" :areas="$this->areas" :acciones="$this->acciones"
        :entidades="$this->entidades" :usuarios="$this->usuarios" :detalle-id="$detalleId" />
</div>
