# Demo, preguntas de defensa y evidencias — legal, publicación, cuentas y app

TG-398 (K44). Es la aportación de esta área a tres cosas que arma el equipo:
el guion de la demo (K16), el banco de preguntas de defensa (K29) y las
evidencias de cierre.

---

## 1. Filas para el guion de la demo

Cuatro bloques. El tiempo es de ensayo, no de reloj en la defensa.

**Antes de empezar:** tener una cuenta de prueba que todavía no haya aceptado
la versión vigente de los documentos, un celular Android sin la app y una
CLABE de prueba. Nada de esto debe ser de una persona real.

### Bloque 1 — La app se descarga y es nuestra (≈2 min)

| Qué se hace | Qué se dice |
|---|---|
| Abrir https://footwearpoint.app y tocar el botón de descarga | «La app se baja desde un enlace público, sin iniciar sesión» |
| Instalar el APK en el celular | «Está firmada con la llave del proyecto. Android solo acepta una actualización si viene firmada con la misma llave» |
| Mostrar el permiso de notificaciones | «De sus 6 permisos, este es el único que le pregunta a la persona. No pide cámara, ubicación, micrófono ni contactos» |

**Si Uptodown ya publicó la ficha,** enseñarla aquí. Si no, decir que está en
revisión: el enlace público que pide la rúbrica ya funciona sin depender de
eso.

### Bloque 2 — Los documentos legales tienen versión (≈2 min)

| Qué se hace | Qué se dice |
|---|---|
| Entrar al panel con la cuenta de prueba | «Esta cuenta aceptó una versión anterior, así que antes de seguir tiene que aceptar la vigente» |
| Abrir el aviso o los términos desde /legales | «Cada documento tiene su versión y su fecha» |
| Aceptar y llegar al panel | «Se guarda qué versión aceptó, cuándo y desde qué IP, sin borrar las aceptaciones anteriores» |
| Entrar a la app con otra cuenta pendiente | «La app pide lo mismo, y la versión la pone el servidor, no el celular» |

**Lo que hay que decir sin rodeos:** los textos son un borrador alineado a lo
que hace el sistema y todavía pasan por revisión jurídica. No afirmar que
cumplen la ley.

### Bloque 3 — La auditoría dice quién entró y qué cambió (≈2 min)

| Qué se hace | Qué se dice |
|---|---|
| Abrir /auditoria y filtrar por «Sesiones» | «Cada inicio y cierre de sesión queda con persona, fecha y hora, en el panel y en la app» |
| Cambiar un dato en Configuración general | «Se guarda solo lo que cambió, con su valor anterior y el nuevo» |
| Guardar una CLABE de prueba y abrir su registro | «La CLABE queda enmascarada: solo se ven los últimos 4 dígitos. Las contraseñas y los tokens nunca se guardan» |

### Bloque 4 — El admin general ve a su personal (≈1 min)

Este bloque depende de que TG-335 (K37) ya esté en main.

| Qué se hace | Qué se dice |
|---|---|
| Abrir /admin/usuarios y filtrar por rol o distribuidora | «Aquí está el personal de toda la plataforma. Los clientes no aparecen» |
| Abrir una ficha | «Roles por distribuidora, último inicio de sesión y movimientos. Es de solo lectura, y ninguna pantalla muestra contraseñas» |

---

## 2. Preguntas de defensa

Ocho, con lo que hay que contestar. La tarjeta pide cuatro como mínimo; las
otras son margen por si el jurado se mete por otro lado.

**1. ¿Por qué firmar la app con una llave propia?**

Porque Android solo instala una actualización si viene firmada con la misma
llave que la versión instalada. Con la llave de depuración no se puede publicar
en serio, y cambiar de llave obliga a desinstalar una vez. La llave original
vive fuera del repositorio y tiene un respaldo cifrado; si se perdiera, ya no
se podría actualizar la app para quien la tiene instalada.

**2. ¿Qué permisos pide la app y por qué tan pocos?**

Seis, y solo uno se le pregunta a la persona: las notificaciones. Los demás son
internet, estado de la red y los que necesita Firebase para recibir avisos, más
uno interno de Android. Las fotos del perfil y del comprobante se eligen con el
selector del sistema, que entrega solo la foto escogida; por eso la app no pide
acceso al almacenamiento.

**3. ¿Qué pasa cuando cambia el aviso de privacidad o los términos?**

Cada documento tiene su versión. Al subirla, quien aceptó una anterior tiene que
aceptar la nueva, en el panel y en la app. La versión la pone el servidor: antes
la app mandaba «1.0» fija. Se conserva el historial, una fila por cuenta,
documento y versión. Y una prueba guarda la huella de cada texto: si alguien
cambia el texto sin subir la versión, la prueba falla.

**4. ¿El aviso y los términos cumplen la ley?**

No lo afirmamos. Son un borrador que describe lo que el sistema hace de verdad,
tomando como referencia la LFPDPPP publicada el 20/03/2025 y, para la
mensualidad, el artículo 76 Bis de la LFPC. Faltan la revisión jurídica y
definir el domicilio del responsable, quién atiende las solicitudes ARCO y los
tribunales competentes. Mientras tanto, los textos remiten al correo de
contacto y no inventan esos datos.

**5. ¿Por qué licencia propietaria y no MIT?**

El proyecto traía «MIT» heredado de la plantilla de Laravel; nadie lo había
decidido. El equipo confirmó el 25/10/2026 que es software propietario, con
todos los derechos reservados. Por eso hay un archivo LICENSE y composer.json
dice «proprietary». Las bibliotecas de código abierto que usamos conservan su
propia licencia.

**6. ¿Por qué la auditoría guarda la IP en el panel y no en la app?**

Porque solo guardamos lo que el aviso de privacidad declara. El aviso cubre la
bitácora del panel, «quién hizo qué, cuándo y desde qué IP», y no declara la IP
para la app. El navegador no se guarda en ningún caso. Si algún día hiciera
falta, primero se cambia el aviso y se pide aceptarlo.

**7. ¿Cómo evitan que un dato sensible termine en la auditoría?**

Hay un filtro único por el que pasa todo lo que se registra. Quita contraseñas,
tokens y credenciales, y enmascara la CLABE, incluso si una pantalla los manda
por error y aunque vengan dentro de otro dato. Además se guarda solo lo que
cambió, no el registro completo.

**8. ¿Por qué no está en Google Play?**

Hoy se distribuye como APK firmado, con descarga directa desde la página y con
Uptodown. Google Play queda como siguiente paso. No hay versión para iPhone: el
proyecto móvil solo tiene Android.

---

## 3. Evidencias

### Lo que ya está y solo hay que enlazar

| Área | Evidencia | Dónde |
|---|---|---|
| Licencia | LICENSE y datos del proyecto | [PR #295](https://github.com/Kevin-VR-99/footwearpoint/pull/295) |
| Publicación | Firma del APK y custodia de la llave | [PR #296](https://github.com/Kevin-VR-99/footwearpoint/pull/296) |
| Publicación | Preparación para Uptodown | [PR #297](https://github.com/Kevin-VR-99/footwearpoint/pull/297) |
| App | Arreglos antes de publicar | [PR #309](https://github.com/Kevin-VR-99/footwearpoint/pull/309) |
| App | Versión 1.3.4+9 | [PR #329](https://github.com/Kevin-VR-99/footwearpoint/pull/329) |
| Legal | Versión por documento y nueva aceptación | [PR #322](https://github.com/Kevin-VR-99/footwearpoint/pull/322) |
| Legal | Aviso de privacidad | [PR #324](https://github.com/Kevin-VR-99/footwearpoint/pull/324) |
| Legal | Términos y condiciones | [PR #325](https://github.com/Kevin-VR-99/footwearpoint/pull/325) y [PR #330](https://github.com/Kevin-VR-99/footwearpoint/pull/330) |
| Auditoría | Inicios y cierres de sesión | [PR #331](https://github.com/Kevin-VR-99/footwearpoint/pull/331) |
| Auditoría | Acciones que no se registraban | [PR #332](https://github.com/Kevin-VR-99/footwearpoint/pull/332) |
| Cuentas | Listado y ficha del personal | [PR #337](https://github.com/Kevin-VR-99/footwearpoint/pull/337) |
| Publicación | Documento de Publicación del Software v3 | Carpeta Sprint_5 del Drive |

### Capturas que faltan tomar

Conviene tomarlas **en producción** y con cuentas de prueba:

1. La página de inicio con el botón de descarga de la app.
2. La pantalla de aceptación en el panel, con las versiones pendientes.
3. La misma pantalla en la app.
4. La auditoría filtrada por «Sesiones».
5. Un registro con su valor anterior y el nuevo.
6. Un registro de datos bancarios con la CLABE enmascarada.
7. El listado de usuarios del admin general y una ficha.
8. La ficha de Uptodown, cuando esté publicada.

### Pendiente de esta área

Las 5 cuentas de admin general (K27) esperan los correos de cada integrante.
