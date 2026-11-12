{{--
    Términos y condiciones de uso (TG-321, K22). Borrador alineado al
    sistema; pasará por revisión jurídica antes de darse por definitivo.

    Lo usan la pantalla de aceptar documentos (auth.aceptar-legales) y la
    página pública /terminos. Los datos sin definir (jurisdicción) salen de
    config/legal.php con un texto neutro de respaldo. La licencia («todos los
    derechos reservados») la confirmó el equipo el 25-oct-2026 y es la misma
    del archivo LICENSE.

    Reglas para editarlo:
    - Solo reglas que existen en el código. Lo que el equipo no ha decidido no
      se inventa: va como pendiente en el PR.
    - Vencimiento, periodo de gracia y suspensión por falta de pago NO van
      aquí: son de K41 (términos v1.3).
    - Referencia: LFPC, art. 76 Bis (última reforma DOF 12/12/2025),
      verificado en diputados.gob.mx el 12/11/2026.
    - Los ids de las secciones llevan el prefijo «terminos-» porque la
      pantalla de aceptar muestra este parcial junto al del aviso.
--}}
@php
    $correo = config('legal.correo_contacto');
    $jurisdiccion = config('legal.jurisdiccion');

    $secciones = [
        'terminos-que-es'      => 'Qué es FootwearPoint y quién es quién',
        'terminos-cuentas'     => 'Quién puede usar la plataforma',
        'terminos-mensualidad' => 'Planes y mensualidad de las distribuidoras',
        'terminos-pagos'       => 'Pagos de los pedidos',
        'terminos-pedidos'     => 'Pedidos, anticipos y entregas',
        'terminos-vales'       => 'Vales y notas de cambio',
        'terminos-contenido'   => 'Software, contenido y fotos',
        'terminos-responsabilidad' => 'Disponibilidad y responsabilidad',
        'terminos-cambios'     => 'Cambios a estos términos',
        'terminos-ley'         => 'Ley aplicable y jurisdicción',
        'terminos-contacto'    => 'Contacto',
    ];
    $numero = array_flip(array_keys($secciones));
    $titulo = fn (string $id) => ($numero[$id] + 1).'. '.$secciones[$id];
@endphp
<div class="fp-legal space-y-4 text-slate-700 leading-relaxed">
    <p>
        Estos términos regulan el uso de <strong>FootwearPoint</strong>: el sitio web, el panel, la app móvil
        y las tiendas públicas. Al crear una cuenta o usar la plataforma aceptas estos términos y el
        <a href="{{ route('aviso-privacidad') }}" class="fp-enlace">aviso de privacidad</a>.
    </p>

    <nav aria-labelledby="terminos-indice-titulo" class="rounded-xl border border-slate-200 bg-white p-4">
        <h2 id="terminos-indice-titulo" class="text-sm font-semibold text-fp-sidebar">Contenido</h2>
        <ol class="mt-2 grid gap-x-6 sm:grid-cols-2">
            @foreach ($secciones as $id => $nombre)
                <li>
                    <a href="#{{ $id }}" class="fp-enlace inline-block py-1.5">{{ $loop->iteration }}. {{ $nombre }}</a>
                </li>
            @endforeach
        </ol>
    </nav>

    <h2 id="terminos-que-es" class="fp-legal-titulo">{{ $titulo('terminos-que-es') }}</h2>
    <p>
        FootwearPoint es una plataforma tecnológica para distribuidoras de calzado por catálogo. Les ayuda a
        llevar su catálogo, sus pedidos a fábrica, los anticipos y saldos, los vales, los cambios y la
        recolección en sucursal. <strong>FootwearPoint no es el vendedor final del calzado</strong>: no compra,
        no vende, no entrega y no cobra el calzado.
    </p>
    <ul class="list-disc pl-5 space-y-1">
        <li><strong>La plataforma (FootwearPoint)</strong> da el software como servicio: el panel web, la app y las tiendas públicas.</li>
        <li><strong>La distribuidora</strong> contrata la plataforma y paga una mensualidad. Es quien vende el calzado, fija precios, anticipos y plazos, cobra, entrega y atiende cambios.</li>
        <li><strong>El cliente mayorista</strong> compra calzado a una distribuidora para revenderlo.</li>
        <li><strong>El cliente directo</strong> compra calzado a una distribuidora para sí mismo.</li>
    </ul>
    <p>La compraventa del calzado es entre la distribuidora y su cliente mayorista o directo.</p>

    <h2 id="terminos-cuentas" class="fp-legal-titulo">{{ $titulo('terminos-cuentas') }}</h2>
    <ul class="list-disc pl-5 space-y-1">
        <li><strong>Debes ser mayor de edad</strong> (18 años o más) y tener capacidad legal para aceptar estos términos. Si registras una distribuidora, declaras que puedes actuar en su nombre.</li>
        <li>Debes dar datos verdaderos y mantener tu contraseña en secreto. Eres responsable de lo que se haga con tu cuenta.</li>
        <li>No debes usar la plataforma para fines ilícitos, suplantar a otra persona, intentar ver datos de otra distribuidora ni afectar su funcionamiento. El mal uso puede llevar a que tu cuenta se desactive.</li>
    </ul>

    <h2 id="terminos-mensualidad" class="fp-legal-titulo">{{ $titulo('terminos-mensualidad') }}</h2>
    <p>Las distribuidoras pagan una mensualidad en pesos mexicanos (MXN) según el plan que eligen:</p>
    <div class="overflow-x-auto">
        <table class="min-w-full text-left text-sm">
            <caption class="sr-only">Planes de la mensualidad</caption>
            <thead>
                <tr class="border-b border-slate-200 text-fp-sidebar">
                    <th scope="col" class="py-1.5 pr-4 font-semibold">Plan</th>
                    <th scope="col" class="py-1.5 pr-4 font-semibold">Precio al mes</th>
                    <th scope="col" class="py-1.5 font-semibold">Líneas incluidas</th>
                </tr>
            </thead>
            <tbody>
                <tr class="border-b border-slate-100"><td class="py-1.5 pr-4">Básico</td><td class="py-1.5 pr-4">$299 MXN</td><td class="py-1.5">2</td></tr>
                <tr class="border-b border-slate-100"><td class="py-1.5 pr-4">Pro</td><td class="py-1.5 pr-4">$599 MXN</td><td class="py-1.5">5</td></tr>
                <tr><td class="py-1.5 pr-4">Enterprise</td><td class="py-1.5 pr-4">$999 MXN</td><td class="py-1.5">10</td></tr>
            </tbody>
        </table>
    </div>
    <ul class="list-disc pl-5 space-y-1">
        <li>Cada línea adicional a las incluidas cuesta <strong>$150 MXN</strong> al mes. Se cobra en el momento en que se agrega.</li>
        <li><strong>El primer mes se paga antes de usar el panel.</strong> Mientras no se pague, el administrador solo puede entrar a la pantalla de pago.</li>
        <li>Cada pago cubre un mes. <strong>No hay cobros automáticos</strong>: para seguir el mes siguiente, el administrador vuelve a pagar desde el panel, con Mercado Pago.</li>
        <li><strong>Para no renovar</strong> basta con no pagar el mes siguiente; no se hace ningún cargo adicional. Para cancelar el servicio y dar de baja tu distribuidora, escríbenos al correo de contacto.</li>
        <li>Los cambios de precio se avisarán por la plataforma o por correo antes de aplicarse, y aplican desde el siguiente mes.</li>
        <li><strong>Facturación:</strong> hoy FootwearPoint no emite factura electrónica (CFDI) por la mensualidad ni cobra IVA sobre ella. Si eso cambia, se avisará con una nueva versión de estos términos antes de aplicarse.</li>
    </ul>

    <h2 id="terminos-pagos" class="fp-legal-titulo">{{ $titulo('terminos-pagos') }}</h2>
    <p>Cada distribuidora decide qué formas de pago acepta. En la plataforma pueden ser:</p>
    <ul class="list-disc pl-5 space-y-1">
        <li><strong>Mercado Pago:</strong> el pago se hace en Mercado Pago, en la cuenta de la distribuidora y conforme a los términos de Mercado Pago. FootwearPoint no ve ni guarda datos de tarjetas.</li>
        <li><strong>Transferencia:</strong> el dinero va de tu banco a la cuenta de la distribuidora, cuya CLABE ves en el pedido. Puedes subir tu comprobante. El pago cuenta cuando la distribuidora lo confirma.</li>
        <li><strong>En la sucursal:</strong> pagas directamente a la distribuidora y ella lo registra.</li>
    </ul>
    <p>
        En todos los casos <strong>FootwearPoint no recibe ni guarda el dinero</strong> de los pedidos. Las
        aclaraciones sobre un pago, un pedido, un cambio o una devolución se atienden con la distribuidora.
    </p>

    <h2 id="terminos-pedidos" class="fp-legal-titulo">{{ $titulo('terminos-pedidos') }}</h2>
    <ul class="list-disc pl-5 space-y-1">
        <li>Los precios, el anticipo mínimo y los plazos los define cada distribuidora.</li>
        <li>Muchos productos se piden a fábrica. Un pedido depende de que la fábrica tenga el producto y puede surtirse solo en parte.</li>
        <li>El anticipo aparta tu pedido y cuenta como parte del pago; el resto se paga como lo indique la distribuidora.</li>
        <li>Los pedidos se entregan en la sucursal de la distribuidora. Cuando el tuyo está listo, ves en el pedido hasta qué día puedes recogerlo. Si pasa esa fecha sin recogerlo, el pedido se marca como no recogido y la distribuidora te dirá qué sigue.</li>
        <li>La calidad, la garantía y la entrega del calzado corresponden a la distribuidora y, en su caso, a la fábrica.</li>
    </ul>

    <h2 id="terminos-vales" class="fp-legal-titulo">{{ $titulo('terminos-vales') }}</h2>
    <p>
        Un vale es un saldo a tu favor con una distribuidora. Puedes pedir un cambio dentro del plazo que marca
        tu distribuidora; cuando procede, se entrega como vale.
    </p>
    <p>Cuando devuelves producto de un pedido ya entregado, la distribuidora te da una <strong>nota de cambio</strong>:</p>
    <ul class="list-disc pl-5 space-y-1">
        <li>Su importe es <strong>lo que realmente pagaste</strong> por lo que devuelves.</li>
        <li>Puedes devolver solo una parte del pedido: algunos productos o algunas piezas.</li>
        <li><strong>Su vigencia la define tu distribuidora</strong>; la fecha de vencimiento aparece en cada nota.</li>
        <li>Solo se usa con <strong>la distribuidora que la emitió</strong>, en un pedido tuyo, <strong>completa y en un solo pedido</strong>.</li>
        <li>Si el pedido cuesta menos que la nota, la nota se usa completa y la diferencia queda en un <strong>vale nuevo</strong> a tu favor, que vence <strong>{{ \App\Models\Vale::DIAS_VIGENCIA_TIPIFICADO }} días</strong> después de generarse.</li>
        <li><strong>No se usa en Venta local</strong> (las ventas que se registran en la sucursal).</li>
    </ul>

    <h2 id="terminos-contenido" class="fp-legal-titulo">{{ $titulo('terminos-contenido') }}</h2>
    <p>
        El software, el diseño, la marca, la documentación y el contenido original de FootwearPoint son
        propiedad de sus autores; <strong>todos los derechos reservados</strong>. FootwearPoint no es software
        de código abierto.
    </p>
    <p>
        Mientras tu cuenta esté activa, tienes una licencia de uso <strong>limitada, no exclusiva,
        intransferible y revocable</strong> para usar FootwearPoint como servicio. Esa licencia no te da
        ninguna titularidad sobre el software. Sin autorización previa y por escrito de sus autores, no se
        permite copiar ni publicar el código, modificarlo ni crear obras derivadas, hacer ingeniería inversa,
        descompilarlo, distribuirlo, sublicenciarlo, venderlo, rentarlo ni retirar los avisos de autoría.
    </p>
    <p>
        <strong>Fotos y demás contenido que subes:</strong> quien sube fotos, logotipos, catálogos o
        descripciones declara que tiene derecho a usarlos y que no infringen derechos de otras personas.
        Conserva sus derechos sobre ellos y autoriza a FootwearPoint a mostrarlos en el panel, la app y la
        tienda pública solo para prestar el servicio.
    </p>

    <h2 id="terminos-responsabilidad" class="fp-legal-titulo">{{ $titulo('terminos-responsabilidad') }}</h2>
    <p>
        Procuramos que la plataforma esté disponible y funcione bien, pero puede haber interrupciones por
        mantenimiento o por fallas de terceros (hospedaje, red, Mercado Pago). FootwearPoint no responde por
        el calzado ni por los acuerdos entre la distribuidora y sus clientes.
    </p>

    <h2 id="terminos-cambios" class="fp-legal-titulo">{{ $titulo('terminos-cambios') }}</h2>
    <p>
        Cuando cambien estos términos, la nueva versión se publicará en
        <a href="{{ route('terminos') }}" class="fp-enlace break-all">{{ route('terminos') }}</a> con su número
        y su fecha, y te pediremos aceptarla dentro de la plataforma. Las aceptaciones de versiones anteriores
        se conservan con su número de versión.
    </p>

    <h2 id="terminos-ley" class="fp-legal-titulo">{{ $titulo('terminos-ley') }}</h2>
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

    <h2 id="terminos-contacto" class="fp-legal-titulo">{{ $titulo('terminos-contacto') }}</h2>
    <p>Dudas, aclaraciones o cancelaciones: <a href="mailto:{{ $correo }}" class="fp-enlace">{{ $correo }}</a>.</p>

    <p class="text-xs text-slate-600">Términos y condiciones · Versión {{ \App\Support\DocumentosLegales::version('terminos_condiciones') }} · Última actualización: {{ \App\Support\DocumentosLegales::fecha('terminos_condiciones') }}</p>
</div>
