<?php

use App\Models\ConfiguracionCiclo;
use App\Models\ConfiguracionDistribuidora;
use App\Models\Distribuidora;
use App\Models\PlanSuscripcion;
use App\Models\Sucursal;
use App\Models\Suscripcion;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use App\Models\DistribuidoraStaff;
use App\Models\Usuario;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

new #[Layout('layouts.admin')] #[Title('Distribuidoras — Admin')] class extends Component {
    public string $filtroEstado = '';
    public string $mensaje = '';

    // Asignar suscripción
    public bool $mostrarSuscripcion = false;
    public ?int $distribuidoraSuscripcionId = null;
    public string $distribuidoraSuscripcionNombre = '';
    public string $plan_id = '';
    public string $lineas_extra_contratadas = '0';
    public string $meses = '1';
    public bool $renovacion_automatica = true;

    public bool $mostrandoFormularioCrear = false;

    public string $nuevo_nombre_comercial = '';
    public string $nuevo_razon_social = '';
    public string $nuevo_rfc = '';
    public string $nuevo_slug = '';
    public string $nuevo_subdominio = '';
    public string $nuevo_email_publico = '';
    public string $nuevo_telefono_publico = '';
    public string $nuevo_direccion_publica = '';
    public string $nuevo_descripcion_publica = '';
    public string $nuevo_horario_publico = '';
    public bool $nuevo_marketplace_visible = true;
    public bool $nuevo_activar_ya = true; // activa al crear (admin)

    // Admin de la distribuidora (opcional pero recomendado)
    public string $admin_nombre = '';
    public string $admin_email = '';
    public string $admin_password = '';

    public function mount()
    {
        if (!Auth::check()) {
            return $this->redirect(route('login'), navigate: true);
        }

        setPermissionsTeamId(0);

        if (!Auth::user()->hasRole('admin_general')) {
            abort(403, 'Solo admin general.');
        }
    }

    public function abrirFormularioCrear(): void
    {
        $this->resetValidation();
        $this->mensaje = '';
        $this->nuevo_nombre_comercial = '';
        $this->nuevo_razon_social = '';
        $this->nuevo_rfc = '';
        $this->nuevo_slug = '';
        $this->nuevo_subdominio = '';
        $this->nuevo_email_publico = '';
        $this->nuevo_telefono_publico = '';
        $this->nuevo_direccion_publica = '';
        $this->nuevo_descripcion_publica = '';
        $this->nuevo_horario_publico = '';
        $this->nuevo_marketplace_visible = true;
        $this->nuevo_activar_ya = true;
        $this->admin_nombre = '';
        $this->admin_email = '';
        $this->admin_password = '';
        $this->mostrandoFormularioCrear = true;
        $this->mostrarSuscripcion = false;
    }

    public function cancelarCrear(): void
    {
        $this->mostrandoFormularioCrear = false;
    }

    public function updatedNuevoNombreComercial($value): void
    {
        if ($this->nuevo_slug === '' || $this->nuevo_slug === Str::slug($this->nuevo_nombre_comercial)) {
            // no auto-overwrite if user typed slug manually after first fill — simple: always suggest
        }
        $base = Str::slug($value);
        $this->nuevo_slug = $base;
        if ($this->nuevo_subdominio === '') {
            $this->nuevo_subdominio = $base;
        }
    }

    public function crearDistribuidora(): void
    {
        $this->mensaje = '';

        $this->validate([
            'nuevo_nombre_comercial' => ['required', 'string', 'max:150'],
            'nuevo_razon_social' => ['nullable', 'string', 'max:200'],
            'nuevo_rfc' => ['nullable', 'string', 'max:20'],
            'nuevo_slug' => ['required', 'string', 'max:120', 'unique:distribuidoras,slug', 'alpha_dash'],
            'nuevo_subdominio' => ['nullable', 'string', 'max:80', 'alpha_dash'],
            'nuevo_email_publico' => ['nullable', 'email', 'max:190'],
            'nuevo_telefono_publico' => ['nullable', 'string', 'max:30'],
            'nuevo_direccion_publica' => ['nullable', 'string', 'max:300'],
            'nuevo_descripcion_publica' => ['nullable', 'string'],
            'nuevo_horario_publico' => ['nullable', 'string', 'max:300'],
            'admin_nombre' => ['nullable', 'string', 'max:150'],
            'admin_email' => ['nullable', 'email', 'max:190', 'unique:usuarios,email'],
            'admin_password' => ['nullable', 'string', 'min:8'],
        ]);

        // Si ponen admin, nombre/email/password obligatorios juntos
        if ($this->admin_email !== '' || $this->admin_password !== '' || $this->admin_nombre !== '') {
            $this->validate([
                'admin_nombre' => ['required', 'string', 'max:150'],
                'admin_email' => ['required', 'email', 'max:190', 'unique:usuarios,email'],
                'admin_password' => ['required', 'string', 'min:8'],
            ]);
        }

        $plan = PlanSuscripcion::where('nombre', 'Básico')->first() ?? PlanSuscripcion::first();

        try {
            DB::transaction(function () use ($plan) {
                $estado = $this->nuevo_activar_ya ? 'activa' : 'pendiente';

                $d = Distribuidora::create([
                    'nombre_comercial' => $this->nuevo_nombre_comercial,
                    'razon_social' => $this->nuevo_razon_social ?: null,
                    'rfc' => $this->nuevo_rfc ?: null,
                    'slug' => $this->nuevo_slug,
                    'subdominio' => $this->nuevo_subdominio ?: $this->nuevo_slug,
                    'descripcion_publica' => $this->nuevo_descripcion_publica ?: null,
                    'direccion_publica' => $this->nuevo_direccion_publica ?: null,
                    'telefono_publico' => $this->nuevo_telefono_publico ?: null,
                    'email_publico' => $this->nuevo_email_publico ?: null,
                    'horario_publico' => $this->nuevo_horario_publico ?: null,
                    'marketplace_visible' => $this->nuevo_marketplace_visible && $estado === 'activa',
                    'estado' => $estado,
                    'fecha_solicitud' => now(),
                    'fecha_aprobacion' => $estado === 'activa' ? now() : null,
                ]);

                Sucursal::withoutGlobalScopes()->create([
                    'distribuidora_id' => $d->id,
                    'nombre' => 'Sucursal Principal',
                    'direccion' => $d->direccion_publica ?? 'Sin dirección',
                    'telefono' => $d->telefono_publico,
                    'es_principal' => true,
                    'activa' => true,
                ]);

                ConfiguracionDistribuidora::withoutGlobalScopes()->create([
                    'distribuidora_id' => $d->id,
                    'anticipo_por_producto' => 100.0,
                    'dias_solicitud_cambio' => 12,
                    'dias_gestion_devolucion' => 20,
                    'dias_vigencia_vale' => 90,
                    'dias_maximos_recoleccion' => 5,
                    'moneda' => 'MXN',
                    'zona_horaria' => 'America/Mexico_City',
                ]);

                ConfiguracionCiclo::withoutGlobalScopes()->create([
                    'distribuidora_id' => $d->id,
                    'dia_cierre' => 5,
                    'hora_cierre' => '18:00:00',
                    'dia_solicitud_fabrica' => 5,
                    'dias_estimados_llegada' => 5,
                    'activa' => true,
                ]);

                if ($plan && $estado === 'activa') {
                    Suscripcion::withoutGlobalScopes()->create([
                        'distribuidora_id' => $d->id,
                        'plan_id' => $plan->id,
                        'fecha_inicio' => now()->toDateString(),
                        'fecha_fin' => now()->addMonth()->toDateString(),
                        'estado' => 'activa',
                        'precio_base_contratado' => $plan->precio_base_mensual,
                        'lineas_incluidas_contratadas' => $plan->lineas_incluidas,
                        'precio_linea_extra_contratado' => $plan->precio_linea_extra,
                        'lineas_extra_contratadas' => 0,
                        'renovacion_automatica' => true,
                    ]);
                }

                if ($this->admin_email !== '') {
                    $usuario = Usuario::create([
                        'nombre' => $this->admin_nombre,
                        'email' => $this->admin_email,
                        'password' => Hash::make($this->admin_password),
                        'estado' => 'activo',
                    ]);

                    DistribuidoraStaff::withoutGlobalScopes()->create([
                        'distribuidora_id' => $d->id,
                        'usuario_id' => $usuario->id,
                        'tipo' => 'admin', // o el valor que uses en seeder
                        'estado' => 'activo',
                        'fecha_alta' => now(),
                    ]);

                    setPermissionsTeamId($d->id);
                    $usuario->assignRole('admin_distribuidora');
                    setPermissionsTeamId(0);
                }
            });
        } catch (\Throwable $e) {
            $this->mensaje = '';
            $this->addError('nuevo_nombre_comercial', $e->getMessage());
            return;
        }

        $this->mostrandoFormularioCrear = false;
        $this->mensaje = 'Distribuidora creada correctamente.';
    }

    public function getDistribuidorasProperty()
    {
        $query = Distribuidora::query()
            ->with([
                'suscripciones' => function ($q) {
                    $q->where('estado', 'activa')->latest('id');
                },
            ])
            ->orderByDesc('id');

        if ($this->filtroEstado !== '') {
            $query->where('estado', $this->filtroEstado);
        }

        return $query->get();
    }

    public function getPlanesActivosProperty()
    {
        return PlanSuscripcion::where('activo', true)->orderBy('precio_base_mensual')->get();
    }

    public function aprobar(int $id)
    {
        $distribuidora = Distribuidora::findOrFail($id);

        if ($distribuidora->estado !== 'pendiente') {
            $this->mensaje = 'Solo se pueden aprobar distribuidoras pendientes.';
            return;
        }

        $plan = PlanSuscripcion::where('nombre', 'Básico')->first() ?? PlanSuscripcion::first();

        if (!$plan) {
            $this->mensaje = 'No hay planes configurados.';
            return;
        }

        DB::transaction(function () use ($distribuidora, $plan) {
            $distribuidora->update([
                'estado' => 'activa',
                'fecha_aprobacion' => now(),
            ]);

            Sucursal::withoutGlobalScopes()->firstOrCreate(
                [
                    'distribuidora_id' => $distribuidora->id,
                    'es_principal' => true,
                ],
                [
                    'nombre' => 'Sucursal Principal',
                    'direccion' => $distribuidora->direccion_publica ?? 'Sin dirección',
                    'telefono' => $distribuidora->telefono_publico,
                    'activa' => true,
                ],
            );

            ConfiguracionDistribuidora::withoutGlobalScopes()->firstOrCreate(
                ['distribuidora_id' => $distribuidora->id],
                [
                    'anticipo_por_producto' => 100.0,
                    'dias_solicitud_cambio' => 12,
                    'dias_gestion_devolucion' => 20,
                    'dias_vigencia_vale' => 90,
                    'dias_maximos_recoleccion' => 5,
                    'moneda' => 'MXN',
                    'zona_horaria' => 'America/Mexico_City',
                ],
            );

            ConfiguracionCiclo::withoutGlobalScopes()->firstOrCreate(
                ['distribuidora_id' => $distribuidora->id],
                [
                    'dia_cierre' => 5,
                    'hora_cierre' => '18:00:00',
                    'dia_solicitud_fabrica' => 5,
                    'dias_estimados_llegada' => 5,
                    'activa' => true,
                ],
            );

            Suscripcion::withoutGlobalScopes()->create([
                'distribuidora_id' => $distribuidora->id,
                'plan_id' => $plan->id,
                'fecha_inicio' => now()->toDateString(),
                'fecha_fin' => now()->addMonth()->toDateString(),
                'estado' => 'activa',
                'precio_base_contratado' => $plan->precio_base_mensual,
                'lineas_incluidas_contratadas' => $plan->lineas_incluidas,
                'precio_linea_extra_contratado' => $plan->precio_linea_extra,
                'lineas_extra_contratadas' => 0,
                'renovacion_automatica' => true,
            ]);
        });

        $this->mensaje = "Distribuidora «{$distribuidora->nombre_comercial}» aprobada.";
    }

    public function suspender(int $id)
    {
        $d = Distribuidora::findOrFail($id);

        if ($d->estado !== 'activa') {
            $this->mensaje = 'Solo se pueden suspender distribuidoras activas.';
            return;
        }

        $d->update(['estado' => 'suspendida']);
        $this->mensaje = "Distribuidora «{$d->nombre_comercial}» suspendida.";
    }

    public function reactivar(int $id)
    {
        $d = Distribuidora::findOrFail($id);

        if ($d->estado !== 'suspendida') {
            $this->mensaje = 'Solo se pueden reactivar distribuidoras suspendidas.';
            return;
        }

        $d->update(['estado' => 'activa']);
        $this->mensaje = "Distribuidora «{$d->nombre_comercial}» reactivada.";
    }

    public function toggleMarketplace(int $id)
    {
        $d = Distribuidora::findOrFail($id);

        if ($d->estado !== 'activa' && !$d->marketplace_visible) {
            $this->mensaje = 'Solo distribuidoras activas pueden ser visibles en marketplace.';
            return;
        }

        $d->update(['marketplace_visible' => !$d->marketplace_visible]);
        $estado = $d->marketplace_visible ? 'visible' : 'oculta';
        $this->mensaje = "Marketplace: «{$d->nombre_comercial}» ahora está {$estado}.";
    }

    public function abrirSuscripcion(int $id)
    {
        $d = Distribuidora::with([
            'suscripciones' => function ($q) {
                $q->where('estado', 'activa')->latest('id');
            },
        ])->findOrFail($id);

        if (!in_array($d->estado, ['activa', 'suspendida'])) {
            $this->mensaje = 'Solo se puede asignar suscripción a distribuidoras activas o suspendidas.';
            return;
        }

        $this->distribuidoraSuscripcionId = $d->id;
        $this->distribuidoraSuscripcionNombre = $d->nombre_comercial;

        $activa = $d->suscripciones->first();

        if ($activa) {
            // Ya tiene plan: precargar datos para cambiar
            $this->plan_id = (string) $activa->plan_id;
            $this->lineas_extra_contratadas = (string) $activa->lineas_extra_contratadas;
            $this->meses = '1';
            $this->renovacion_automatica = (bool) $activa->renovacion_automatica;
        } else {
            // No tiene plan: formulario vacío
            $this->plan_id = '';
            $this->lineas_extra_contratadas = '0';
            $this->meses = '1';
            $this->renovacion_automatica = true;
        }

        $this->mostrarSuscripcion = true;
    }

    public function cancelarSuscripcion()
    {
        $this->mostrarSuscripcion = false;
        $this->distribuidoraSuscripcionId = null;
    }

    public function guardarSuscripcion()
    {
        $this->validate([
            'plan_id' => 'required|exists:planes_suscripcion,id',
            'lineas_extra_contratadas' => 'nullable|integer|min:0',
            'meses' => 'required|integer|min:1|max:24',
        ]);

        $distribuidora = Distribuidora::findOrFail($this->distribuidoraSuscripcionId);
        $plan = PlanSuscripcion::findOrFail($this->plan_id);

        if (!$plan->activo) {
            $this->mensaje = 'El plan seleccionado no está activo.';
            return;
        }

        Suscripcion::withoutGlobalScopes()
            ->where('distribuidora_id', $distribuidora->id)
            ->where('estado', 'activa')
            ->update([
                'estado' => 'cancelada',
                'fecha_fin' => now()->toDateString(),
            ]);

        Suscripcion::withoutGlobalScopes()->create([
            'distribuidora_id' => $distribuidora->id,
            'plan_id' => $plan->id,
            'fecha_inicio' => now()->toDateString(),
            'fecha_fin' => now()->addMonths((int) $this->meses)->toDateString(),
            'estado' => 'activa',
            'precio_base_contratado' => $plan->precio_base_mensual,
            'lineas_incluidas_contratadas' => $plan->lineas_incluidas,
            'precio_linea_extra_contratado' => $plan->precio_linea_extra,
            'lineas_extra_contratadas' => (int) $this->lineas_extra_contratadas,
            'renovacion_automatica' => $this->renovacion_automatica,
        ]);

        $this->mensaje = "Suscripción «{$plan->nombre}» asignada a «{$distribuidora->nombre_comercial}».";
        $this->cancelarSuscripcion();
    }
};
?>

<div>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-2xl font-bold text-slate-900">Distribuidoras</h2>
            <p class="text-sm text-slate-500">Solicitudes, altas y estado de las tiendas</p>
        </div>
        @if (!$mostrandoFormularioCrear)
            <button type="button" wire:click="abrirFormularioCrear"
                class="rounded-lg bg-[#2563EB] text-white text-sm font-medium px-4 py-2 hover:bg-blue-700">
                + Nueva distribuidora
            </button>
        @endif
    </div>

    @if ($mensaje)
        <div class="mb-4 rounded-lg bg-green-50 text-green-700 text-sm p-3">
            {{ $mensaje }}
        </div>
    @endif

    @if ($mostrandoFormularioCrear)
        <form wire:submit="crearDistribuidora"
            class="mb-6 bg-white rounded-xl border border-slate-200 p-5 space-y-4 max-w-3xl">
            <h3 class="font-semibold text-slate-800">Nueva distribuidora</h3>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium mb-1">Nombre comercial *</label>
                    <input type="text" wire:model.live="nuevo_nombre_comercial"
                        class="w-full rounded-lg border-slate-300 text-sm">
                    @error('nuevo_nombre_comercial')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Razón social</label>
                    <input type="text" wire:model="nuevo_razon_social"
                        class="w-full rounded-lg border-slate-300 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">RFC</label>
                    <input type="text" wire:model="nuevo_rfc" class="w-full rounded-lg border-slate-300 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Slug * (URL interna)</label>
                    <input type="text" wire:model="nuevo_slug" class="w-full rounded-lg border-slate-300 text-sm">
                    @error('nuevo_slug')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Subdominio</label>
                    <input type="text" wire:model="nuevo_subdominio"
                        class="w-full rounded-lg border-slate-300 text-sm" placeholder="calzados-ejemplo">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Email público</label>
                    <input type="email" wire:model="nuevo_email_publico"
                        class="w-full rounded-lg border-slate-300 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Teléfono público</label>
                    <input type="text" wire:model="nuevo_telefono_publico"
                        class="w-full rounded-lg border-slate-300 text-sm">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium mb-1">Dirección pública</label>
                    <input type="text" wire:model="nuevo_direccion_publica"
                        class="w-full rounded-lg border-slate-300 text-sm">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium mb-1">Descripción pública</label>
                    <textarea wire:model="nuevo_descripcion_publica" rows="2" class="w-full rounded-lg border-slate-300 text-sm"></textarea>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium mb-1">Horario público</label>
                    <input type="text" wire:model="nuevo_horario_publico"
                        class="w-full rounded-lg border-slate-300 text-sm" placeholder="Lunes a sábado 9:00–19:00">
                </div>
            </div>

            <div class="flex flex-wrap gap-4 text-sm">
                <label class="flex items-center gap-2">
                    <input type="checkbox" wire:model="nuevo_activar_ya" class="rounded border-slate-300">
                    Activar de inmediato (si no, queda pendiente)
                </label>
                <label class="flex items-center gap-2">
                    <input type="checkbox" wire:model="nuevo_marketplace_visible" class="rounded border-slate-300">
                    Visible en marketplace
                </label>
            </div>

            <div class="border-t pt-4">
                <h4 class="text-sm font-semibold text-slate-700 mb-2">Administrador de la tienda (opcional)</h4>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">Nombre</label>
                        <input type="text" wire:model="admin_nombre"
                            class="w-full rounded-lg border-slate-300 text-sm">
                        @error('admin_nombre')
                            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Email login</label>
                        <input type="email" wire:model="admin_email"
                            class="w-full rounded-lg border-slate-300 text-sm">
                        @error('admin_email')
                            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Contraseña</label>
                        <input type="password" wire:model="admin_password"
                            class="w-full rounded-lg border-slate-300 text-sm">
                        @error('admin_password')
                            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>

            <div class="flex gap-2">
                <button type="submit" class="rounded-lg bg-[#2563EB] text-white text-sm font-medium px-4 py-2">
                    Crear distribuidora
                </button>
                <button type="button" wire:click="cancelarCrear" class="text-sm text-slate-600 px-4 py-2">
                    Cancelar
                </button>
            </div>
        </form>
    @endif

    @if ($mostrarSuscripcion)
        <div class="mb-6 bg-white rounded-xl border border-slate-200 p-5">
            <h3 class="font-semibold mb-1">Asignar plan</h3>
            <p class="text-sm text-slate-500 mb-4">{{ $distribuidoraSuscripcionNombre }}</p>

            <form wire:submit="guardarSuscripcion" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">Plan</label>
                    <select wire:model="plan_id" class="w-full rounded-lg border-slate-300">
                        <option value="">Selecciona un plan</option>
                        @foreach ($this->planesActivos as $plan)
                            <option value="{{ $plan->id }}">
                                {{ $plan->nombre }} — ${{ number_format($plan->precio_base_mensual, 2) }}
                                ({{ $plan->lineas_incluidas }} líneas)
                            </option>
                        @endforeach
                    </select>
                    @error('plan_id')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1">Líneas extra</label>
                    <input type="number" min="0" wire:model="lineas_extra_contratadas"
                        class="w-full rounded-lg border-slate-300">
                    @error('lineas_extra_contratadas')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1">Meses</label>
                    <input type="number" min="1" max="24" wire:model="meses"
                        class="w-full rounded-lg border-slate-300">
                    @error('meses')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex items-center gap-2 pt-6">
                    <input type="checkbox" wire:model="renovacion_automatica"
                        class="rounded border-slate-300 text-blue-600">
                    <span class="text-sm">Renovación automática</span>
                </div>

                <div class="md:col-span-2 flex gap-2">
                    <button type="submit" class="rounded-lg bg-[#111E38] text-white text-sm px-4 py-2">
                        Asignar
                    </button>
                    <button type="button" wire:click="cancelarSuscripcion"
                        class="rounded-lg border border-slate-200 text-sm px-4 py-2">
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    @endif

    <div class="mb-4 flex gap-2 flex-wrap">
        <button wire:click="$set('filtroEstado', '')"
            class="px-3 py-1.5 rounded-lg text-sm {{ $filtroEstado === '' ? 'bg-[#111E38] text-white' : 'bg-white border border-slate-200' }}">
            Todas
        </button>
        @foreach (['pendiente', 'activa', 'suspendida', 'rechazada'] as $estado)
            <button wire:click="$set('filtroEstado', '{{ $estado }}')"
                class="px-3 py-1.5 rounded-lg text-sm {{ $filtroEstado === $estado ? 'bg-[#111E38] text-white' : 'bg-white border border-slate-200' }}">
                {{ ucfirst($estado) }}
            </button>
        @endforeach
    </div>

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-600">
                <tr>
                    <th class="text-left px-4 py-3 font-medium">ID</th>
                    <th class="text-left px-4 py-3 font-medium">Nombre</th>
                    <th class="text-left px-4 py-3 font-medium">Estado</th>
                    <th class="text-left px-4 py-3 font-medium">Marketplace</th>
                    <th class="text-left px-4 py-3 font-medium">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($this->distribuidoras as $d)
                    <tr>
                        <td class="px-4 py-3">{{ $d->id }}</td>
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ $d->nombre_comercial }}</div>
                            <div class="text-xs text-slate-400">{{ $d->slug }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <span
                                class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium
                                {{ $d->estado === 'activa' ? 'bg-green-100 text-green-700' : '' }}
                                {{ $d->estado === 'pendiente' ? 'bg-amber-100 text-amber-700' : '' }}
                                {{ $d->estado === 'suspendida' ? 'bg-red-100 text-red-700' : '' }}
                                {{ $d->estado === 'rechazada' ? 'bg-slate-100 text-slate-600' : '' }}
                            ">
                                {{ $d->estado }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <button wire:click="toggleMarketplace({{ $d->id }})"
                                class="text-xs {{ $d->marketplace_visible ? 'text-green-600' : 'text-slate-400' }}">
                                {{ $d->marketplace_visible ? 'Visible' : 'Oculta' }}
                            </button>
                        </td>
                        <td class="px-4 py-3 space-x-2">
                            @if ($d->estado === 'pendiente')
                                <button wire:click="aprobar({{ $d->id }})"
                                    class="text-xs text-green-700 hover:underline">Aprobar</button>
                            @endif
                            @if ($d->estado === 'activa')
                                <button wire:click="suspender({{ $d->id }})"
                                    class="text-xs text-red-600 hover:underline">Suspender</button>
                            @endif
                            @if ($d->estado === 'suspendida')
                                <button wire:click="reactivar({{ $d->id }})"
                                    class="text-xs text-blue-700 hover:underline">Reactivar</button>
                            @endif
                            @if (in_array($d->estado, ['activa', 'suspendida']))
                                @php
                                    $tienePlan = $d->suscripciones->where('estado', 'activa')->isNotEmpty();
                                @endphp
                                <button wire:click="abrirSuscripcion({{ $d->id }})"
                                    class="text-xs text-indigo-700 hover:underline">
                                    @if ($tienePlan)
                                        Cambiar plan
                                    @else
                                        Asignar plan
                                    @endif
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-slate-400">
                            No hay distribuidoras con este filtro.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
