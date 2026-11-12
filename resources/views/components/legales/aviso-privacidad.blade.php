{{--
    Aviso de privacidad integral (TG-322, K23). Borrador alineado al sistema;
    pasará por revisión jurídica antes de darse por definitivo.

    Lo usan la pantalla de aceptar documentos (auth.aceptar-legales) y la
    página pública /aviso-privacidad. Referencia: LFPDPPP (DOF 20/03/2025,
    última reforma DOF 14/11/2025), arts. 2 fr. XV, 14, 15, 28, 29, 31 y 36,
    verificados en diputados.gob.mx el 11/11/2026.

    Reglas para editarlo:
    - Solo datos, finalidades y proveedores que existen en el código o en la
      configuración. Lo que no se ha comprobado no va aquí, va en el PR.
    - Los datos que el equipo no ha definido (domicilio, quién atiende ARCO)
      salen de config/legal.php; si faltan se muestra un texto neutro.
    - Los ids de las secciones llevan el prefijo «aviso-» porque la pantalla
      de aceptar muestra este parcial junto al de términos.
--}}
@php
    $correo = config('legal.correo_contacto');
    $domicilio = config('legal.domicilio_responsable');
    $arco = config('legal.contacto_arco');

    $secciones = [
        'aviso-responsable'   => 'Quién es responsable de tus datos',
        'aviso-datos'         => 'Datos personales que tratamos',
        'aviso-finalidades'   => 'Para qué usamos tus datos',
        'aviso-actividad'     => 'Registro de actividad',
        'aviso-tienda'        => 'Tienda pública y mapa',
        'aviso-cookies'       => 'Cookies y datos en tu dispositivo',
        'aviso-proveedores'   => 'Con quién compartimos datos',
        'aviso-arco'          => 'Derechos ARCO',
        'aviso-limitar'       => 'Revocar el consentimiento y limitar el uso',
        'aviso-seguridad'     => 'Seguridad',
        'aviso-cambios'       => 'Cambios a este aviso',
        'aviso-autoridad'     => 'Autoridad',
    ];
    $numero = array_flip(array_keys($secciones));
    $titulo = fn (string $id) => ($numero[$id] + 1).'. '.$secciones[$id];
@endphp
<div class="fp-legal space-y-4 text-slate-700 leading-relaxed">
    <p>
        Este aviso explica qué datos personales se tratan en <strong>FootwearPoint</strong>, para qué se usan,
        con quién se comparten y cómo puedes ejercer tus derechos. Se redactó tomando como referencia la
        Ley Federal de Protección de Datos Personales en Posesión de los Particulares (LFPDPPP), publicada en
        el Diario Oficial de la Federación el 20 de marzo de 2025, con su última reforma del 14 de noviembre
        de 2025.
    </p>

    <nav aria-labelledby="aviso-indice-titulo" class="rounded-xl border border-slate-200 bg-white p-4">
        <h2 id="aviso-indice-titulo" class="text-sm font-semibold text-fp-sidebar">Contenido</h2>
        <ol class="mt-2 grid gap-x-6 sm:grid-cols-2">
            @foreach ($secciones as $id => $nombre)
                <li>
                    <a href="#{{ $id }}" class="fp-enlace inline-block py-1.5">{{ $loop->iteration }}. {{ $nombre }}</a>
                </li>
            @endforeach
        </ol>
    </nav>

    <h2 id="aviso-responsable" class="fp-legal-titulo">{{ $titulo('aviso-responsable') }}</h2>
    <p>En FootwearPoint intervienen dos tipos de responsables, y conviene distinguirlos:</p>
    <ul class="list-disc pl-5 space-y-1">
        <li>
            <strong>FootwearPoint</strong> es la plataforma tecnológica: el panel web, la app móvil y las tiendas
            públicas. No vende calzado. Es responsable de los datos con los que opera la plataforma: las
            solicitudes de alta y las cuentas de las distribuidoras, el inicio de sesión de todas las cuentas,
            la aceptación de estos documentos, la seguridad y el registro de actividad.
        </li>
        <li>
            <strong>Cada distribuidora</strong> es un negocio independiente que vende calzado por catálogo. Es
            responsable de los datos de sus clientes mayoristas, de sus clientes directos y de su personal, que
            ella captura y usa en su negocio. FootwearPoint trata esos datos <strong>por cuenta de la
            distribuidora</strong>, solo para prestarle el servicio, y los mantiene separados de los de las
            demás distribuidoras.
        </li>
    </ul>
    <p>
        Si tu duda es sobre cómo una distribuidora usa tus datos, puedes dirigirte a ella o escribirnos y
        canalizaremos tu solicitud.
    </p>
    <p>
        @if (filled($domicilio))
            Domicilio de FootwearPoint: {{ $domicilio }}.
        @else
            Para conocer el domicilio de FootwearPoint o hacer cualquier trámite, escríbenos al correo de contacto.
        @endif
        Correo de contacto: <a href="mailto:{{ $correo }}" class="fp-enlace">{{ $correo }}</a>.
    </p>

    <h2 id="aviso-datos" class="fp-legal-titulo">{{ $titulo('aviso-datos') }}</h2>
    <p>Según cómo uses la plataforma, se tratan estos datos:</p>
    <ul class="list-disc pl-5 space-y-1">
        <li><strong>Cualquier cuenta:</strong> nombre, correo electrónico, teléfono, contraseña (solo se guarda cifrada, con un algoritmo de un solo sentido), rol, permisos, estado de la cuenta y foto de perfil opcional, que puedes subir desde el panel o desde la app.</li>
        <li><strong>Distribuidoras:</strong> nombre comercial, razón social, RFC, teléfono (y si es de WhatsApp), correo, dirección y horario públicos, logotipo, sucursales con su dirección y teléfono, el plan contratado, la cuenta de Mercado Pago que conectan y, si aceptan transferencias, el banco, la CLABE y el titular de su cuenta.</li>
        <li><strong>Personal de la distribuidora:</strong> nombre, correo, teléfono, dirección, foto, puesto y permisos dentro del panel.</li>
        <li><strong>Clientes mayoristas:</strong> nombre, correo, teléfono, foto, número de cliente y las distribuidoras con las que trabajan. En la app pueden anotar el nombre y el teléfono de sus propios clientes, con lo que les venden, para organizar sus pedidos.</li>
        <li><strong>Clientes directos:</strong> nombre, correo, teléfono, foto, dirección de contacto y las notas que la distribuidora agregue.</li>
        <li><strong>Operación:</strong> pedidos, anticipos, saldos, pagos, vales, cambios y devoluciones, entregas y recolección en sucursal, y ventas registradas en la sucursal.</li>
        <li><strong>Pagos:</strong> los pagos en línea se hacen en <strong>Mercado Pago</strong>. FootwearPoint <strong>no recibe ni guarda</strong> números de tarjeta; solo recibe el resultado del pago: identificador, monto, estado y fecha. Si pagas un pedido por transferencia, se guarda el <strong>comprobante</strong> que subes (la imagen o el archivo, su nombre y quién lo subió), que solo pueden ver tú y la distribuidora de ese pedido.</li>
        <li><strong>Datos técnicos:</strong> el identificador de tu celular para enviarte notificaciones push; la dirección IP y el navegador de tu sesión en el sitio web; la dirección IP, la fecha y la versión con que aceptaste estos documentos; la bitácora de acciones del panel (quién hizo qué, cuándo y desde qué IP), y el registro de actividad (punto 4).</li>
    </ul>
    <p>
        <strong>No tratamos datos personales sensibles</strong> (por ejemplo, de salud, origen étnico, creencias
        o preferencias). Te pedimos no escribirlos en notas, fotos ni comentarios.
    </p>

    <h2 id="aviso-finalidades" class="fp-legal-titulo">{{ $titulo('aviso-finalidades') }}</h2>
    <p><strong>Finalidades necesarias para el servicio:</strong></p>
    <ul class="list-disc pl-5 space-y-1">
        <li><strong>Cuentas:</strong> crear, verificar y administrar tu cuenta, iniciar sesión, cambiar o recuperar tu contraseña y confirmar cambios de correo o teléfono.</li>
        <li><strong>Pedidos:</strong> registrar pedidos de calzado y darles seguimiento hasta la entrega o la recolección en sucursal, y llevar anticipos, saldos, vales, cambios y devoluciones.</li>
        <li><strong>Pagos:</strong> cobrar anticipos, saldos y la suscripción de las distribuidoras por medio de Mercado Pago, y conciliar los pagos. Para los pagos por transferencia, mostrar a quien debe pagar un pedido el banco, la CLABE y el titular de su distribuidora, y guardar el comprobante para que ella lo revise. El dinero va directo de tu banco al de la distribuidora: FootwearPoint no lo recibe ni lo custodia.</li>
        <li><strong>Catálogo:</strong> publicar los productos de cada distribuidora, con sus fotos, precios y tallas, en el panel, la app y su tienda pública.</li>
        <li><strong>App móvil:</strong> mostrarte tu catálogo, tus pedidos y tus vales, y enviarte avisos por notificación push, por la bandeja de la app y por correo.</li>
        <li><strong>Soporte:</strong> atender dudas, aclaraciones y solicitudes como las de derechos ARCO.</li>
        <li><strong>Seguridad y auditoría:</strong> llevar la bitácora de acciones y el registro de actividad, limitar los intentos de acceso y prevenir el uso indebido de la plataforma.</li>
        <li><strong>Obligaciones legales:</strong> cumplir las que apliquen y atender requerimientos de autoridades competentes.</li>
    </ul>
    <p>
        <strong>Finalidades secundarias:</strong> hoy FootwearPoint <strong>no</strong> usa tus datos para
        mercadotecnia, publicidad ni prospección comercial. Si algún día quisiera hacerlo, lo informaría en una
        nueva versión de este aviso y te pediría tu consentimiento antes.
    </p>

    <h2 id="aviso-actividad" class="fp-legal-titulo">{{ $titulo('aviso-actividad') }}</h2>
    <p>
        Para saber cuánto se usa la plataforma y por qué medio, se guarda <strong>un registro por persona, por
        día y por canal</strong> (por ejemplo, el panel web o la app). Ese registro <strong>no guarda tu
        dirección IP</strong> ni lo que hiciste, solo que ese día usaste ese canal.
    </p>
    <ul class="list-disc pl-5 space-y-1">
        <li>El registro por persona se conserva <strong>90 días</strong> y después se borra.</li>
        <li>Antes de borrarlo se calculan <strong>totales mensuales</strong> de uso, que se conservan <strong>24 meses</strong>.</li>
    </ul>

    <h2 id="aviso-tienda" class="fp-legal-titulo">{{ $titulo('aviso-tienda') }}</h2>
    <p>
        Cada distribuidora puede tener una tienda pública con su nombre, su dirección, su teléfono o WhatsApp,
        su horario y sus productos. Esos son datos del negocio que la distribuidora decide publicar.
    </p>
    <p>
        La tienda muestra un <strong>mapa de Google Maps</strong> con la ubicación de la distribuidora. Al cargar
        el mapa, tu navegador se conecta directamente con Google, que recibe tu dirección IP y puede usar sus
        propias cookies conforme a su aviso de privacidad. FootwearPoint no recibe datos tuyos a través del mapa.
        Los botones para ver la ruta o abrir el mapa te llevan al sitio de Google.
    </p>

    <h2 id="aviso-cookies" class="fp-legal-titulo">{{ $titulo('aviso-cookies') }}</h2>
    <p>
        El sitio web usa solo <strong>cookies técnicas</strong>, necesarias para funcionar: la de tu sesión, la de
        protección contra falsificación de peticiones (CSRF) y, en el panel de las distribuidoras, una para
        recordar tu inicio de sesión. <strong>No usamos cookies de analítica, de publicidad ni de rastreo.</strong>
        Si borras las cookies desde tu navegador, tendrás que volver a iniciar sesión.
    </p>
    <p>
        El sitio guarda en tu navegador una copia de los archivos que no cambian (como el logotipo) y de la
        página de inicio sin datos de nadie, para que cargue más rápido. La app guarda tu sesión cifrada en el
        almacenamiento seguro de tu celular, y las fotos de los productos en su memoria temporal.
    </p>

    <h2 id="aviso-proveedores" class="fp-legal-titulo">{{ $titulo('aviso-proveedores') }}</h2>
    <p>
        <strong>No vendemos ni rentamos tus datos.</strong> Para operar, la plataforma usa proveedores que tratan
        datos por cuenta nuestra (encargados), y que algunas veces lo hacen fuera de México:
    </p>
    <ul class="list-disc pl-5 space-y-1">
        <li><strong>Railway:</strong> servidores y base de datos de la plataforma, donde también se guardan los comprobantes de transferencia.</li>
        <li><strong>Cloudflare R2:</strong> almacenamiento de fotos, logotipos, catálogos, exportaciones y respaldos.</li>
        <li><strong>Google:</strong> envío de correos del sistema (Gmail) y de notificaciones push (Firebase Cloud Messaging), que recibe el identificador de tu celular y el texto del aviso.</li>
        <li><strong>Anthropic:</strong> lectura con inteligencia artificial de los catálogos de fábrica para importar productos. Solo recibe el catálogo y las fotos de los productos, no datos de personas.</li>
    </ul>
    <p>
        Además, para cobrar, el monto y la referencia del pago se comunican a <strong>Mercado Pago</strong>, que
        trata los datos que tú escribes en su sitio conforme a su propio aviso de privacidad. Esa comunicación, y
        la que se hace con la distribuidora con la que compras, sirve para cumplir la relación que tienes con
        ellas y con la plataforma, por lo que no requiere tu consentimiento (artículo 36, fracciones IV y VII,
        de la LFPDPPP). También se comunicarán datos cuando lo exija una ley o una autoridad competente. No se
        hacen otras transferencias que requieran tu consentimiento.
    </p>
    <p>
        Cuando compartes un producto o un pedido por WhatsApp, lo haces tú, desde tu propia app de WhatsApp.
    </p>

    <h2 id="aviso-arco" class="fp-legal-titulo">{{ $titulo('aviso-arco') }}</h2>
    <p>
        Tienes derecho a <strong>Acceder</strong> a tus datos, a <strong>Rectificarlos</strong> si son inexactos,
        a <strong>Cancelarlos</strong> y a <strong>Oponerte</strong> a su tratamiento. Tu nombre, tu teléfono, tu
        correo y tu foto puedes consultarlos y corregirlos tú mismo desde tu perfil en el panel o en la app.
    </p>
    <p>
        Para cualquier otro caso, envía tu solicitud a <a href="mailto:{{ $correo }}" class="fp-enlace">{{ $correo }}</a>@if (filled($arco)), dirigida a {{ $arco }}@endif.
        Incluye lo que pide el artículo 28 de la LFPDPPP:
    </p>
    <ol class="list-[lower-alpha] pl-5 space-y-1">
        <li>tu nombre y un medio para responderte, como el correo de tu cuenta;</li>
        <li>un documento que acredite tu identidad o, si te representa alguien, la identidad y la representación de esa persona;</li>
        <li>qué derecho quieres ejercer o qué solicitas;</li>
        <li>la descripción de los datos de que se trata (no hace falta si pides acceso), y</li>
        <li>cualquier documento que ayude a localizarlos. Si pides una rectificación, indica qué hay que corregir y adjunta lo que lo respalde.</li>
    </ol>
    <p>
        Responderemos dentro de los plazos que fija la LFPDPPP. Si los datos los capturó una distribuidora para
        su negocio, te diremos si la solicitud le corresponde a ella.
    </p>

    <h2 id="aviso-limitar" class="fp-legal-titulo">{{ $titulo('aviso-limitar') }}</h2>
    <p>
        Puedes revocar tu consentimiento, o pedir que limitemos el uso o la divulgación de tus datos, con una
        solicitud al mismo correo y con los mismos datos del punto anterior. Si debemos conservar algún dato por
        una obligación legal, no podremos borrarlo de inmediato, y si revocas el consentimiento para una
        finalidad necesaria, quizá ya no puedas usar la plataforma.
    </p>
    <p>
        Sin escribirnos, puedes apagar las notificaciones push desde los ajustes de tu celular (los avisos
        seguirán en la bandeja de la app) y borrar las cookies desde tu navegador.
    </p>

    <h2 id="aviso-seguridad" class="fp-legal-titulo">{{ $titulo('aviso-seguridad') }}</h2>
    <p>
        Usamos conexión cifrada (HTTPS), contraseñas cifradas, credenciales de Mercado Pago cifradas, separación
        de los datos de cada distribuidora, roles y permisos, límite de intentos de acceso, bitácora y respaldos.
        Quienes trabajan en la plataforma deben guardar confidencialidad.
    </p>

    <h2 id="aviso-cambios" class="fp-legal-titulo">{{ $titulo('aviso-cambios') }}</h2>
    <p>
        Cualquier cambio se publicará en <a href="{{ route('aviso-privacidad') }}" class="fp-enlace break-all">{{ route('aviso-privacidad') }}</a>
        con un número de versión y una fecha nuevos, y te pediremos aceptarlo dentro de la plataforma. Las aceptaciones
        de versiones anteriores se conservan con su número de versión.
    </p>

    <h2 id="aviso-autoridad" class="fp-legal-titulo">{{ $titulo('aviso-autoridad') }}</h2>
    <p>
        Si consideras que tu derecho a la protección de datos personales fue vulnerado, puedes acudir a la
        autoridad en la materia, que conforme a la LFPDPPP es la
        <strong>Secretaría Anticorrupción y Buen Gobierno</strong>.
    </p>

    <p class="text-xs text-slate-600">Aviso de privacidad · Versión {{ \App\Support\DocumentosLegales::version('aviso_privacidad') }} · Última actualización: {{ \App\Support\DocumentosLegales::fecha('aviso_privacidad') }}</p>
</div>
