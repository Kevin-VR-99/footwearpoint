<?php

use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.panel')] #[Title('Anticipos — FootwearPoint')] class extends Component
{
    public function mount(): void
    {
        if (! Auth::check()) {
            $this->redirect(route('login'), navigate: true);

            return;
        }

        if (Tenant::id() === null) {
            abort(403, 'No se pudo determinar la distribuidora.');
        }
    }
};
?>

<div>
    <x-panel.pestanas-compras activa="anticipos" />

    <x-panel.encabezado titulo="Anticipos"
        subtitulo="La configuración y el listado de esta pestaña todavía no están aquí." class="mb-6" />

    <p data-anticipos-vacio>Esta pestaña no muestra anticipos ni plazos.</p>
</div>
