{{--
    Términos y condiciones de uso, versión 1.1 (24-oct-2026).

    Lo usan la pantalla de aceptar documentos (auth.aceptar-legales) y la
    página pública /terminos. Los datos sin definir (jurisdicción) salen de
    config/legal.php con un texto neutro de respaldo. La cláusula de licencia
    ("todos los derechos reservados") es una propuesta pendiente de confirmar
    por el equipo. Las aceptaciones de la versión 1.0 se conservan.
--}}
@php
    $correo = config('legal.correo_contacto');
    $jurisdiccion = config('legal.jurisdiccion');
@endphp
<div class="space-y-4">
    <p>
        Estos términos regulan el uso de FootwearPoint (sitio web, panel y app móvil). Al crear una cuenta o
        usar la plataforma aceptas estos términos y el
        <a href="{{ route('aviso-privacidad') }}" class="fp-enlace">aviso de privacidad</a>.
    </p>

    <h2 class="font-semibold text-slate-800">1. Qué es FootwearPoint</h2>
    <p>
        FootwearPoint es una plataforma tecnológica que ayuda a las distribuidoras de calzado por catálogo a
        gestionar pedidos a fábrica, anticipos, saldos, vales, cambios y la recolección en sucursal
        (Click & Collect). FootwearPoint no es el vendedor final del calzado; actúa como plataforma
        tecnológica. La compraventa es entre la distribuidora y su revendedor o cliente.
    </p>

    <h2 class="font-semibold text-slate-800">2. Cuentas y uso aceptable</h2>
    <ul class="list-disc pl-5 space-y-1">
        <li>Debes dar datos verdaderos y mantener tu contraseña en secreto; eres responsable de lo que se haga con tu cuenta.</li>
        <li>No debes usar la plataforma para fines ilícitos, suplantar a otra persona, intentar acceder a datos de otra distribuidora ni afectar su funcionamiento.</li>
        <li>El incumplimiento de pagos o mal uso de la cuenta puede derivar en suspensión del acceso.</li>
    </ul>

    <h2 class="font-semibold text-slate-800">3. Pedidos, precios y pagos entre distribuidora y revendedor</h2>
    <ul class="list-disc pl-5 space-y-1">
        <li>Los precios, anticipos y plazos los define cada distribuidora.</li>
        <li>Los pedidos están sujetos a disponibilidad de fábrica y surtido parcial.</li>
        <li>El anticipo mínimo y políticas de cambio/devolución se aplican según la configuración de la distribuidora.</li>
        <li>Los pagos en línea se procesan en Mercado Pago, en la cuenta de la distribuidora, conforme a sus términos. FootwearPoint no guarda datos de tarjetas.</li>
        <li>Las aclaraciones sobre un pedido, cambio o devolución se atienden primero con la distribuidora.</li>
    </ul>

    <h2 class="font-semibold text-slate-800">4. Planes de suscripción para distribuidoras</h2>
    <p>Las distribuidoras pagan una suscripción mensual en pesos mexicanos (MXN) según el plan elegido:</p>
    <div class="overflow-x-auto">
        <table class="min-w-full text-left text-sm">
            <thead>
                <tr class="border-b border-slate-200">
                    <th class="py-1 pr-4">Plan</th>
                    <th class="py-1 pr-4">Precio mensual</th>
                    <th class="py-1">Líneas incluidas</th>
                </tr>
            </thead>
            <tbody>
                <tr class="border-b border-slate-100"><td class="py-1 pr-4">Básico</td><td class="py-1 pr-4">$299 MXN</td><td class="py-1">2</td></tr>
                <tr class="border-b border-slate-100"><td class="py-1 pr-4">Pro</td><td class="py-1 pr-4">$599 MXN</td><td class="py-1">5</td></tr>
                <tr><td class="py-1 pr-4">Enterprise</td><td class="py-1 pr-4">$999 MXN</td><td class="py-1">10</td></tr>
            </tbody>
        </table>
    </div>
    <ul class="list-disc pl-5 space-y-1">
        <li>Cada línea adicional a las incluidas cuesta <strong>$150 MXN</strong> al mes.</li>
        <li>La suscripción se cobra por Mercado Pago. Si no se paga, el acceso de la distribuidora puede suspenderse hasta regularizar el pago.</li>
        <li>Los cambios de precio se avisarán con anticipación por la plataforma o por correo y aplican desde el siguiente periodo.</li>
    </ul>

    <h2 class="font-semibold text-slate-800">5. Contenido y propiedad intelectual</h2>
    <p>
        El software, el diseño, la marca y el contenido de FootwearPoint son propiedad de sus autores;
        <strong>todos los derechos reservados</strong>. Se otorga a cada usuario una licencia de uso limitada,
        no exclusiva e intransferible mientras su cuenta esté activa; no se permite copiar, modificar,
        descompilar ni redistribuir el software. Las distribuidoras conservan los derechos sobre las fotos,
        logotipos y catálogos que suben, y autorizan a FootwearPoint a mostrarlos dentro de la plataforma para
        prestar el servicio.
    </p>

    <h2 class="font-semibold text-slate-800">6. Disponibilidad y responsabilidad</h2>
    <p>
        Procuramos que la plataforma esté disponible y funcione bien, pero puede haber interrupciones por
        mantenimiento o fallas de terceros (hospedaje, red, Mercado Pago). FootwearPoint no responde por la
        calidad, entrega o garantía del calzado, que corresponden a la distribuidora y a la fábrica.
    </p>

    <h2 class="font-semibold text-slate-800">7. Cambios a estos términos</h2>
    <p>
        Podemos actualizar estos términos; la nueva versión se publicará en
        <a href="{{ route('terminos') }}" class="fp-enlace">{{ route('terminos') }}</a> con su fecha y se avisará
        dentro de la plataforma o por correo. Las aceptaciones de versiones anteriores se conservan.
    </p>

    <h2 class="font-semibold text-slate-800">8. Ley aplicable y jurisdicción</h2>
    <p>
        Estos términos se rigen por las leyes federales de los Estados Unidos Mexicanos. Para cualquier
        controversia, las partes se someten a
        @if (filled($jurisdiccion))
            {{ $jurisdiccion }},
        @else
            los tribunales competentes que correspondan conforme a la ley,
        @endif
        sin perjuicio de los derechos que la legislación de protección al consumidor otorgue a quien tenga
        ese carácter.
    </p>

    <h2 class="font-semibold text-slate-800">9. Contacto</h2>
    <p>Dudas o aclaraciones: <a href="mailto:{{ $correo }}" class="fp-enlace">{{ $correo }}</a>.</p>

    <p class="text-xs text-slate-500">Versión {{ config('legal.version') }} — Última actualización: {{ config('legal.fecha_actualizacion') }}</p>
</div>
