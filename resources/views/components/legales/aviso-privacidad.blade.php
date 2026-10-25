{{--
    Aviso de privacidad integral, versión 1.1 (24-oct-2026).

    Lo usan la pantalla de aceptar documentos (auth.aceptar-legales) y la
    página pública /aviso-privacidad. Base: Ley Federal de Protección de Datos
    Personales en Posesión de los Particulares (DOF 20/03/2025), arts. 14, 15,
    35 y 36. Los datos sin definir (domicilio, quién atiende ARCO) salen de
    config/legal.php; si faltan se muestra un texto neutro.
    Las aceptaciones de la versión 1.0 se conservan con su versión.
--}}
@php
    $correo = config('legal.correo_contacto');
    $domicilio = config('legal.domicilio_responsable');
    $arco = config('legal.contacto_arco');
@endphp
<div class="space-y-4">
    <p>
        En cumplimiento de la Ley Federal de Protección de Datos Personales en Posesión de los Particulares
        (LFPDPPP, publicada en el Diario Oficial de la Federación el 20 de marzo de 2025) y demás normativa
        aplicable, se pone a tu disposición este aviso de privacidad integral.
    </p>

    <h2 class="font-semibold text-slate-800">1. Identidad y domicilio del responsable</h2>
    <p>
        <strong>FootwearPoint</strong>, plataforma tecnológica para distribuidoras de calzado por catálogo, es
        responsable del tratamiento de los datos personales de las cuentas de la plataforma. La
        <strong>distribuidora afiliada</strong> con la que haces tus pedidos es responsable de los datos de
        sus clientes y de su personal que ella misma captura y usa para su negocio; FootwearPoint los trata
        por su cuenta para prestarle el servicio.
    </p>
    <p>
        @if (filled($domicilio))
            Domicilio del responsable: {{ $domicilio }}.
        @else
            Para conocer el domicilio del responsable o hacer cualquier trámite, escríbenos al correo de contacto.
        @endif
        Correo de contacto: <a href="mailto:{{ $correo }}" class="fp-enlace">{{ $correo }}</a>.
    </p>

    <h2 class="font-semibold text-slate-800">2. Datos personales que recabamos</h2>
    <ul class="list-disc pl-5 space-y-1">
        <li><strong>Identificación y contacto:</strong> nombre, correo electrónico, teléfono y, en su caso, dirección de entrega o de sucursal.</li>
        <li><strong>Cuenta:</strong> contraseña (solo se guarda cifrada con un algoritmo de un solo sentido), rol, permisos y foto de perfil opcional.</li>
        <li><strong>Imágenes:</strong> foto de perfil, logotipo de la distribuidora y fotos de productos que se suben a la plataforma.</li>
        <li><strong>Distribuidoras:</strong> nombre comercial o razón social, RFC, datos de contacto, sucursales y la cuenta de Mercado Pago que conectan.</li>
        <li><strong>Operación:</strong> pedidos, anticipos, saldos, pagos, vales, cambios y entregas. Los revendedores pueden capturar nombre y teléfono de sus propios clientes para organizar pedidos.</li>
        <li><strong>Datos financieros y de pago:</strong> los pagos en línea se hacen en <strong>Mercado Pago</strong>. FootwearPoint <strong>no recibe ni guarda</strong> números de tarjeta ni datos bancarios completos; solo recibe el resultado del pago (identificador, monto, estado y fecha).</li>
        <li><strong>Dispositivo y uso:</strong> identificador del celular para notificaciones push (token de Firebase), dirección IP, fecha y hora de la aceptación de documentos y bitácora de acciones en la plataforma.</li>
    </ul>
    <p>
        <strong>No recabamos datos personales sensibles</strong> (por ejemplo, de salud, origen étnico,
        creencias o preferencias). Te pedimos no incluirlos en notas, fotos ni comentarios.
    </p>

    <h2 class="font-semibold text-slate-800">3. Finalidades del tratamiento</h2>
    <p><strong>Finalidades primarias</strong> (necesarias para darte el servicio; no requieren consentimiento adicional porque derivan de la relación que tienes con nosotros):</p>
    <ul class="list-disc pl-5 space-y-1">
        <li>Crear, verificar y administrar tu cuenta, e iniciar sesión de forma segura.</li>
        <li>Registrar y dar seguimiento a pedidos de calzado bajo pedido, cambios, vales y recolección en sucursal (Click & Collect).</li>
        <li>Cobrar anticipos, saldos y suscripciones por medio de Mercado Pago y conciliar los pagos.</li>
        <li>Enviarte avisos por correo, por la bandeja de la app y por notificación push sobre tus pedidos, pagos, vales y cuenta.</li>
        <li>Recuperar tu contraseña, atender aclaraciones y dar soporte.</li>
        <li>Mantener la seguridad de la plataforma (bitácora, prevención de fraude y de accesos indebidos).</li>
        <li>Cumplir obligaciones legales, fiscales y de facturación cuando aplique.</li>
    </ul>
    <p>
        <strong>Finalidades secundarias:</strong> hoy FootwearPoint <strong>no</strong> usa tus datos para
        mercadotecnia, publicidad ni prospección comercial. Si en el futuro quisiera hacerlo, lo informará
        con una nueva versión de este aviso y pedirá tu consentimiento antes; podrás negarte en cualquier
        momento escribiendo al correo de contacto, sin que eso afecte el servicio.
    </p>

    <h2 class="font-semibold text-slate-800">4. Encargados y transferencias</h2>
    <p><strong>No vendemos ni rentamos tus datos.</strong> Para operar, la plataforma se apoya en proveedores que tratan datos por nuestra cuenta (encargados), bajo obligaciones de confidencialidad y seguridad:</p>
    <ul class="list-disc pl-5 space-y-1">
        <li><strong>Railway</strong>: hospedaje del servidor y de la base de datos.</li>
        <li><strong>Cloudflare</strong>: dominio, red y almacenamiento de archivos e imágenes (R2), exportaciones y respaldos.</li>
        <li><strong>Google Firebase</strong>: envío de notificaciones push (token del dispositivo y texto del aviso).</li>
        <li><strong>Servicio de correo electrónico</strong>: envío de correos del sistema.</li>
        <li><strong>Servicios de inteligencia artificial</strong>: lectura de catálogos de productos para importarlos; solo se envía el catálogo, no datos de clientes.</li>
        <li><strong>Monitoreo de errores</strong> (cuando está activo): datos técnicos de fallas.</li>
    </ul>
    <p>
        Algunos de estos proveedores procesan información fuera de México. Además, para cobrar, el monto y la
        referencia del pago se comparten con <strong>Mercado Pago</strong>, que trata los datos que tú capturas
        en su sitio conforme a su propio aviso de privacidad. Esa comunicación, y la que se hace con la
        distribuidora con la que compras, es necesaria para cumplir la relación jurídica contigo, por lo que no
        requiere tu consentimiento (artículo 36, fracciones IV y VII, de la LFPDPPP). También podremos
        comunicar datos cuando lo exija una ley o una autoridad competente. No hacemos otras transferencias
        que requieran tu consentimiento.
    </p>

    <h2 class="font-semibold text-slate-800">5. Derechos ARCO</h2>
    <p>
        Tienes derecho a <strong>Acceder</strong> a tus datos, <strong>Rectificarlos</strong> si son inexactos,
        <strong>Cancelarlos</strong> y <strong>Oponerte</strong> a su tratamiento. Puedes consultar y corregir tu
        nombre, teléfono, correo y foto desde tu perfil en la app o el panel. Para ejercer cualquier derecho
        ARCO envía una solicitud a <a href="mailto:{{ $correo }}" class="fp-enlace">{{ $correo }}</a>
        @if (filled($arco))
            , dirigida a {{ $arco }},
        @endif
        con: (a) tu nombre y el correo de tu cuenta para responderte; (b) un documento que acredite tu
        identidad o, en su caso, la de tu representante; (c) la descripción clara del derecho que ejerces y de
        los datos de que se trata, y (d) cualquier documento que ayude a localizarlos.
    </p>
    <p>
        Te comunicaremos la respuesta en un plazo máximo de <strong>20 días</strong> contados desde que
        recibimos la solicitud y, si procede, se hará efectiva dentro de los <strong>15 días</strong>
        siguientes. Estos plazos pueden ampliarse una sola vez por un periodo igual cuando se justifique.
        La atención es gratuita, salvo los costos de envío o reproducción que la ley permita.
    </p>

    <h2 class="font-semibold text-slate-800">6. Revocación del consentimiento y limitación del uso</h2>
    <p>
        Puedes revocar el consentimiento que nos diste, o pedir que limitemos el uso o divulgación de tus
        datos, con una solicitud al mismo correo y con los mismos requisitos del punto anterior. Ten en
        cuenta que no siempre podremos atenderla de inmediato si debemos conservar datos por una obligación
        legal, y que revocar el consentimiento para finalidades primarias puede impedir que sigas usando la
        plataforma. También puedes desactivar las notificaciones push desde los ajustes de tu celular; los
        avisos seguirán en la bandeja de la app.
    </p>

    <h2 class="font-semibold text-slate-800">7. Cookies y tecnologías similares</h2>
    <p>
        El sitio web usa únicamente cookies técnicas necesarias para funcionar: la cookie de sesión y la
        protección contra falsificación de peticiones (CSRF) y, en el panel de distribuidoras, una cookie para
        recordar tu inicio de sesión. No usamos cookies de analítica, publicidad ni rastreo de terceros. La
        app móvil guarda tu sesión cifrada en el almacenamiento seguro del celular. Puedes borrar las
        cookies desde tu navegador, pero tendrás que volver a iniciar sesión.
    </p>

    <h2 class="font-semibold text-slate-800">8. Seguridad y confidencialidad</h2>
    <p>
        Aplicamos medidas administrativas, técnicas y físicas: conexión cifrada (HTTPS), contraseñas
        cifradas, credenciales de Mercado Pago cifradas, separación de los datos de cada distribuidora, roles
        y permisos, límite de intentos de acceso, bitácora y respaldos. Quienes intervienen en el
        tratamiento están obligados a guardar confidencialidad.
    </p>

    <h2 class="font-semibold text-slate-800">9. Cambios a este aviso</h2>
    <p>
        Cualquier cambio se publicará en <a href="{{ route('aviso-privacidad') }}" class="fp-enlace">{{ route('aviso-privacidad') }}</a>
        con un número de versión y fecha nuevos, y se te avisará dentro de la plataforma o por correo. Las
        aceptaciones de versiones anteriores se conservan con su número de versión.
    </p>

    <h2 class="font-semibold text-slate-800">10. Autoridad</h2>
    <p>
        Si consideras que tu derecho a la protección de datos personales ha sido vulnerado, puedes acudir a la
        autoridad competente en la materia, que conforme a la LFPDPPP vigente es la
        <strong>Secretaría Anticorrupción y Buen Gobierno</strong> del Gobierno de México.
    </p>

    <p class="text-xs text-slate-500">Versión {{ config('legal.version') }} — Última actualización: {{ config('legal.fecha_actualizacion') }}</p>
</div>
