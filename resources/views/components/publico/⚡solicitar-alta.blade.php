<?php

use App\Models\AceptacionLegal;
use App\Models\PlanSuscripcion;
use App\Models\Usuario;
use App\Services\Distribuidora\CrearDistribuidoraAction;
use App\Services\Distribuidora\LimiteSolicitudesAlta;
use App\Services\Distribuidora\NotificarSolicitudAltaAction;
use App\Services\Distribuidora\SlugDeSolicitudAlta;
use App\Support\MensajeError;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * TG-220 (E2-08) — "Solicitar mi alta": el formulario publico con el que una
 * distribuidora pide entrar a FootwearPoint.
 *
 * Lo que pasa al enviarlo lo hace CrearDistribuidoraAction con activar=false,
 * la misma que usa el admin general: la distribuidora queda PENDIENTE, con su
 * administrador, sus roles y sus datos iniciales. Aqui no se duplica nada de
 * eso. La suscripcion se abre al aprobar (AprobarDistribuidoraAction), con el
 * plan que se eligio aqui.
 *
 * Una pendiente no opera (TG-220 en Distribuidora::ESTADOS_SIN_OPERACION):
 * su administrador no entra al panel ni a la app hasta que la aprueben.
 *
 * Contra envios automaticos, sin servicios de fuera: un campo trampa que
 * tiene que llegar vacio, un tiempo minimo de llenado y un limite por IP.
 */
new #[Layout('layouts.public', ['subtitulo' => 'Solicita tu alta'])]
#[Title('Solicitar mi alta — FootwearPoint')]
class extends Component
{
    /** Segundos que, como minimo, tarda una persona en llenar esto. */
    private const SEGUNDOS_MINIMOS = 3;

    // --- La distribuidora ---
    public string $nombre_comercial = '';
    public string $razon_social = '';
    public string $rfc = '';
    public string $telefono_publico = '';
    public string $email_publico = '';
    public string $direccion_publica = '';
    public string $plan_id = '';

    // --- Su administrador ---
    public string $admin_nombre = '';
    public string $admin_email = '';
    public string $admin_password = '';
    public string $admin_password_confirmation = '';

    public bool $acepta_legales = false;

    /** Campo trampa: una persona no lo ve, un robot lo llena. */
    public string $sitio_web = '';

    /**
     * Cuando se abrio el formulario, para medir el tiempo de llenado.
     *
     * Locked: la pone el servidor en mount(). Sin esto, el navegador la puede
     * mandar con cualquier valor en la peticion de Livewire y saltarse el
     * tiempo minimo, que es justo la defensa que mide.
     */
    #[Locked]
    public int $iniciado_en = 0;

    /**
     * Ya se envio: se muestra la pantalla de confirmacion.
     *
     * Locked: solo la cambia el servidor al terminar. Nadie deberia poder
     * ponerse la pantalla de "recibimos tu solicitud" desde el navegador.
     */
    #[Locked]
    public bool $enviada = false;

    public string $avisoLimite = '';

    public function mount(): void
    {
        $this->iniciado_en = now()->timestamp;
    }

    public function getPlanesProperty()
    {
        return PlanSuscripcion::query()
            ->where('activo', true)
            ->orderBy('precio_base_mensual')
            ->get();
    }

    public function enviar(): void
    {
        $this->avisoLimite = '';

        $limite = app(LimiteSolicitudesAlta::class);
        $ip = request()->ip();

        if ($limite->bloqueado($ip)) {
            $this->avisoLimite = $limite->mensaje($limite->minutosRestantes($ip));
            $this->olvidarPassword();

            return;
        }

        // Este SÍ cuenta aunque el envío termine mal: es lo que impide probar
        // correos uno por uno para averiguar quién ya está registrado.
        $limite->registrarIntento($ip);

        // Pase lo que pase, la contraseña no se queda en el estado del
        // componente, que viaja al navegador en cada ida y vuelta.
        try {
            $this->procesar($limite, $ip);
        } finally {
            $this->olvidarPassword();
        }
    }

    private function procesar(LimiteSolicitudesAlta $limite, ?string $ip): void
    {
        // El RFC se normaliza antes de validar, para revisar formato y
        // unicidad sobre lo mismo que se va a guardar.
        $this->rfc = CrearDistribuidoraAction::normalizarRfc($this->rfc) ?? '';

        $datos = $this->validate($this->reglas(), $this->mensajes());

        // Parece un robot: no se crea nada y no se dice por que. Se ve la
        // misma pantalla de siempre para no darle pistas.
        if ($this->pareceAutomatico()) {
            $this->enviada = true;

            return;
        }

        try {
            $this->guardar($datos, $ip);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            $this->addError('nombre_comercial', MensajeError::GENERICO);

            return;
        }

        $limite->registrar($ip);
        $this->enviada = true;
    }

    private function olvidarPassword(): void
    {
        $this->admin_password = '';
        $this->admin_password_confirmation = '';
    }

    private function guardar(array $datos, ?string $ip): void
    {
        $slug = app(SlugDeSolicitudAlta::class)->paraNombre($this->nombre_comercial);

        $distribuidora = app(CrearDistribuidoraAction::class)->ejecutar(
            [
                'nombre_comercial' => $this->nombre_comercial,
                'razon_social' => $this->razon_social,
                'rfc' => $this->rfc,
                'slug' => $slug,
                'telefono_publico' => $this->telefono_publico,
                'email_publico' => $this->email_publico,
                'direccion_publica' => $this->direccion_publica,
                'plan_solicitado_id' => (int) $this->plan_id,
            ],
            [
                'nombre' => $this->admin_nombre,
                'email' => $this->admin_email,
                'password' => $this->admin_password,
            ],
            // Queda PENDIENTE: la revisa el admin general.
            false,
        );

        $administrador = Usuario::where('email', trim($this->admin_email))->firstOrFail();

        $this->guardarAceptacionLegal($administrador, $ip);

        // Ya esta guardada: si el correo falla, la solicitud se queda.
        app(NotificarSolicitudAltaAction::class)->ejecutar($distribuidora, $administrador);
    }

    /** Igual que en la pantalla de aceptar documentos: version 1.0. */
    private function guardarAceptacionLegal(Usuario $usuario, ?string $ip): void
    {
        foreach (['aviso_privacidad', 'terminos_condiciones'] as $documento) {
            AceptacionLegal::firstOrCreate(
                [
                    'usuario_id' => $usuario->id,
                    'tipo_documento' => $documento,
                    'version' => '1.0',
                ],
                [
                    'fecha_aceptacion' => now(),
                    'ip_origen' => $ip,
                ]
            );
        }
    }

    private function pareceAutomatico(): bool
    {
        if (trim($this->sitio_web) !== '') {
            return true;
        }

        return (now()->timestamp - $this->iniciado_en) < self::SEGUNDOS_MINIMOS;
    }

    private function reglas(): array
    {
        return [
            'nombre_comercial' => ['required', 'string', 'max:150'],
            'razon_social' => ['nullable', 'string', 'max:200'],
            // RFC mexicano: 12 caracteres persona moral, 13 persona fisica.
            'rfc' => ['nullable', 'string', 'regex:/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/u', Rule::unique('distribuidoras', 'rfc')],
            'telefono_publico' => ['nullable', 'string', 'max:30'],
            'email_publico' => ['nullable', 'email', 'max:190'],
            'direccion_publica' => ['nullable', 'string', 'max:300'],
            'plan_id' => ['required', Rule::exists('planes_suscripcion', 'id')->where('activo', true)],
            'admin_nombre' => ['required', 'string', 'max:150'],
            'admin_email' => ['required', 'email', 'max:190', Rule::unique('usuarios', 'email')],
            'admin_password' => ['required', 'string', 'min:8', 'confirmed'],
            'acepta_legales' => ['accepted'],
        ];
    }

    private function mensajes(): array
    {
        return [
            'nombre_comercial.required' => 'El nombre de tu distribuidora es obligatorio.',
            'nombre_comercial.max' => 'El nombre no puede pasar de 150 caracteres.',
            'razon_social.max' => 'La razón social no puede pasar de 200 caracteres.',
            'rfc.regex' => 'El RFC no tiene un formato válido (12 caracteres persona moral o 13 persona física).',
            'rfc.unique' => 'Ese RFC ya está registrado en FootwearPoint.',
            'telefono_publico.max' => 'El teléfono no puede pasar de 30 caracteres.',
            'email_publico.email' => 'El correo de contacto no es válido.',
            'email_publico.max' => 'El correo de contacto no puede pasar de 190 caracteres.',
            'direccion_publica.max' => 'La dirección no puede pasar de 300 caracteres.',
            'plan_id.required' => 'Elige el plan que te gustaría si te aceptamos.',
            'plan_id.exists' => 'Ese plan ya no está disponible. Elige otro.',
            'admin_nombre.required' => 'Tu nombre es obligatorio.',
            'admin_nombre.max' => 'Tu nombre no puede pasar de 150 caracteres.',
            'admin_email.required' => 'Tu correo es obligatorio.',
            'admin_email.email' => 'Tu correo no es válido.',
            'admin_email.max' => 'Tu correo no puede pasar de 190 caracteres.',
            'admin_email.unique' => 'Ese correo ya tiene una cuenta en FootwearPoint.',
            'admin_password.required' => 'Elige una contraseña.',
            'admin_password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'admin_password.confirmed' => 'Las dos contraseñas no coinciden.',
            'acepta_legales.accepted' => 'Para continuar tienes que aceptar el aviso de privacidad y los términos.',
        ];
    }
};
?>

<div class="max-w-3xl mx-auto">
    @if ($enviada)
        {{-- Pantalla de confirmación: qué sigue --}}
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-8 text-center">
            <h1 class="text-2xl font-bold text-slate-900">Recibimos tu solicitud</h1>

            <div class="mt-4 text-slate-600 space-y-3 text-left max-w-xl mx-auto">
                <p><strong>1. Revisa tu correo.</strong> Te mandamos un mensaje para confirmar tu dirección. Ábrelo y sigue el enlace.</p>
                <p><strong>2. Nosotros revisamos tu solicitud.</strong> El equipo de FootwearPoint mira los datos de tu distribuidora.</p>
                <p><strong>3. Te avisamos por correo.</strong> En cuanto quede aprobada podrás entrar con el correo y la contraseña que acabas de elegir.</p>
                <p class="text-sm text-slate-500">Mientras tanto tu cuenta todavía no puede entrar al panel: es lo normal.</p>
            </div>

            <div class="mt-8 flex flex-wrap justify-center gap-3">
                <a href="{{ route('inicio') }}"
                    class="px-5 py-2.5 rounded-lg bg-[#111E38] text-white font-medium hover:bg-[#1E2F52] transition">
                    Volver al inicio
                </a>
                <a href="{{ route('marketplace') }}"
                    class="px-5 py-2.5 rounded-lg border border-slate-300 bg-white text-slate-700 font-medium hover:bg-slate-50 transition">
                    Ver marketplace
                </a>
            </div>
        </div>
    @else
        <div class="mb-8">
            <h1 class="text-2xl font-bold text-slate-900">Solicitar mi alta</h1>
            <p class="mt-1 text-sm text-slate-500">
                Cuéntanos de tu distribuidora. Revisamos tu solicitud y te avisamos por correo.
            </p>
            <p class="mt-2 text-sm text-slate-600">
                Los campos con <span class="text-red-600">*</span> son obligatorios:
                nombre comercial, el plan que te gustaría, tu nombre, tu correo, la contraseña y aceptar los documentos.
            </p>
        </div>

        @if ($errors->any())
            <div id="errores-solicitud" tabindex="-1" x-init="$nextTick(() => $el.focus())"
                class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                <p class="font-semibold">No se envió la solicitud. Revisa lo que falta:</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($avisoLimite)
            <div class="mb-6 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-sm p-4">
                {{ $avisoLimite }}
            </div>
        @endif

        <form wire:submit="enviar" class="space-y-8">
            {{-- Campo trampa: invisible para una persona, tentador para un robot. --}}
            <div aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;" tabindex="-1">
                <label for="sitio_web">No llenes este campo</label>
                <input type="text" id="sitio_web" wire:model="sitio_web" tabindex="-1" autocomplete="off">
            </div>

            {{-- Tu distribuidora --}}
            <section class="bg-white rounded-xl border border-slate-200 p-6 space-y-4">
                <h2 class="font-semibold text-slate-900">Tu distribuidora</h2>

                <div>
                    <label for="nombre_comercial" class="block text-sm font-medium text-slate-700 mb-1">
                        Nombre comercial <span class="text-red-600">*</span>
                    </label>
                    <input type="text" id="nombre_comercial" wire:model="nombre_comercial" required
                        class="w-full rounded-lg text-sm">
                    @error('nombre_comercial') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="razon_social" class="block text-sm font-medium text-slate-700 mb-1">Razón social</label>
                        <input type="text" id="razon_social" wire:model="razon_social"
                            class="w-full rounded-lg text-sm">
                        @error('razon_social') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="rfc" class="block text-sm font-medium text-slate-700 mb-1">RFC</label>
                        <input type="text" id="rfc" wire:model="rfc"
                            class="w-full rounded-lg text-sm uppercase">
                        @error('rfc') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="telefono_publico" class="block text-sm font-medium text-slate-700 mb-1">Teléfono</label>
                        <input type="text" id="telefono_publico" wire:model="telefono_publico"
                            class="w-full rounded-lg text-sm">
                        @error('telefono_publico') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="email_publico" class="block text-sm font-medium text-slate-700 mb-1">Correo de contacto</label>
                        <input type="email" id="email_publico" wire:model="email_publico"
                            class="w-full rounded-lg text-sm">
                        @error('email_publico') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label for="direccion_publica" class="block text-sm font-medium text-slate-700 mb-1">Dirección</label>
                    <input type="text" id="direccion_publica" wire:model="direccion_publica"
                        class="w-full rounded-lg text-sm">
                    @error('direccion_publica') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </section>

            {{-- Preferencia de plan: no se cobra ni se activa hasta que la solicitud se acepte. --}}
            <section class="bg-white rounded-xl border border-slate-200 p-6">
                <h2 class="font-semibold text-slate-900">Plan que te gustaría si te aceptamos <span class="text-red-600">*</span></h2>
                <p class="mt-1 text-sm text-slate-500">Todavía no se cobra ni queda activo. Si aceptamos tu solicitud, este es el plan con el que te gustaría empezar. Después se puede cambiar. Precios en pesos mexicanos, por mes.</p>

                @if ($this->planes->isEmpty())
                    <p class="mt-4 text-sm text-slate-500">
                        Estamos actualizando nuestros planes. Escríbenos y te decimos cuál te conviene.
                    </p>
                @else
                    <div class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-4">
                        @foreach ($this->planes as $plan)
                            <label class="block cursor-pointer rounded-xl border p-4 transition
                                {{ (string) $plan->id === $plan_id ? 'border-[#111E38] ring-2 ring-[#111E38]/20 bg-[#F8FAFF]' : 'border-slate-200 hover:bg-slate-50' }}">
                                <span class="flex items-start gap-2">
                                    <input type="radio" name="plan_id" wire:model.live="plan_id" value="{{ $plan->id }}" required
                                        class="mt-1 text-[#111E38] focus:ring-[#111E38]">
                                    <span>
                                        <span class="block font-semibold text-slate-900">{{ $plan->nombre }}</span>
                                        <span class="block text-lg font-bold text-slate-900">
                                            ${{ number_format((float) $plan->precio_base_mensual, 2) }}
                                        </span>
                                        <span class="block text-xs text-slate-500">
                                            {{ $plan->lineas_incluidas }} línea{{ $plan->lineas_incluidas == 1 ? '' : 's' }} incluida{{ $plan->lineas_incluidas == 1 ? '' : 's' }} ·
                                            extra ${{ number_format((float) $plan->precio_linea_extra, 2) }}
                                        </span>
                                    </span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                @endif

                @error('plan_id') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </section>

            {{-- Tu cuenta --}}
            <section class="bg-white rounded-xl border border-slate-200 p-6 space-y-4">
                <h2 class="font-semibold text-slate-900">Tu cuenta de administrador</h2>
                <p class="text-sm text-slate-500">Con esto entrarás al panel cuando aprobemos tu solicitud.</p>

                <div>
                    <label for="admin_nombre" class="block text-sm font-medium text-slate-700 mb-1">
                        Tu nombre <span class="text-red-600">*</span>
                    </label>
                    <input type="text" id="admin_nombre" wire:model="admin_nombre" required
                        class="w-full rounded-lg text-sm">
                    @error('admin_nombre') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="admin_email" class="block text-sm font-medium text-slate-700 mb-1">
                        Tu correo <span class="text-red-600">*</span>
                    </label>
                    <input type="email" id="admin_email" wire:model="admin_email" required
                        class="w-full rounded-lg text-sm">
                    @error('admin_email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="admin_password" class="block text-sm font-medium text-slate-700 mb-1">
                            Contraseña <span class="text-red-600">*</span>
                        </label>
                        <input type="password" id="admin_password" wire:model="admin_password" required minlength="8"
                            class="w-full rounded-lg text-sm">
                        @error('admin_password') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="admin_password_confirmation" class="block text-sm font-medium text-slate-700 mb-1">
                            Repite la contraseña <span class="text-red-600">*</span>
                        </label>
                        <input type="password" id="admin_password_confirmation" wire:model="admin_password_confirmation" required minlength="8"
                            class="w-full rounded-lg text-sm">
                    </div>
                </div>
            </section>

            {{-- Legales --}}
            <div>
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" wire:model="acepta_legales" required
                        class="mt-1 rounded text-[#111E38] focus:ring-[#111E38]">
                    <span class="text-sm text-slate-700">
                        Acepto el
                        <a href="{{ route('aviso-privacidad') }}" target="_blank" class="text-[#2563EB] hover:underline">aviso de privacidad</a>
                        y los
                        <a href="{{ route('terminos') }}" target="_blank" class="text-[#2563EB] hover:underline">términos y condiciones</a>
                        (versión 1.0). <span class="text-red-600">*</span>
                    </span>
                </label>
                @error('acepta_legales') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <button type="submit"
                class="w-full rounded-lg bg-[#111E38] text-white font-medium py-3 hover:bg-[#1E2F52] transition"
                wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="enviar">Enviar mi solicitud</span>
                <span wire:loading wire:target="enviar">Enviando...</span>
            </button>
        </form>
    @endif
</div>
