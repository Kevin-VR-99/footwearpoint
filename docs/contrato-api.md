# Contrato de API — app móvil FootwearPoint

Historia **E18-03 (TG-97)**. Última revisión completa: **TG-223**, Sprint 4.

Este documento describe los endpoints que consume la app de Flutter: ruta, método, quién puede entrar, qué se manda y qué regresa. Todo está sacado del código real de Laravel (controladores, Form Requests y API Resources), no de suposiciones.

Sirve para construir las pantallas de la app sin tener que leer el backend.

**Qué entra aquí:** todo lo que la app usa, a detalle. Los endpoints que existen pero solo usa el panel web están en la sección 13, con una línea cada uno.

## Índice

1. [Lo que aplica a todos los endpoints](#1-lo-que-aplica-a-todos-los-endpoints)
2. [Autenticación](#2-autenticación)
3. [Catálogo](#3-catálogo)
4. [Pedidos](#4-pedidos)
5. [Vales](#5-vales)
6. [Notificaciones](#6-notificaciones-bandeja-dentro-de-la-app)
7. [Perfil del usuario](#7-perfil-del-usuario-e1-05--tg-110)
8. [Marketplace](#8-marketplace-directorio-público)
9. [Clientes del cliente mayorista](#9-clientes-del-cliente-mayorista-e11-01)
10. [Solicitudes de cambio de producto](#10-solicitudes-de-cambio-de-producto-tg-254-e12-01)
11. [Cambios del Sprint 4](#11-cambios-del-sprint-4)
12. [De dónde salió cada cosa](#12-de-dónde-salió-cada-cosa)
13. [Endpoints que no usa la app](#13-endpoints-que-no-usa-la-app)

---

## 1. Lo que aplica a todos los endpoints

**Dirección base:** la que esté en `mobile/lib/config.dart`.

- Emulador Android → `http://10.0.2.2:8000/api`
- Celular físico → `http://<IP-de-tu-computadora>:8000/api`, y Laravel levantado con `php artisan serve --host=0.0.0.0`

**Encabezados que manda la app siempre:**

```
Accept: application/json
Content-Type: application/json
Authorization: Bearer <token>     (en todo lo que no sea login)
```

De esto ya se encarga `ApiService`; las pantallas no lo arman a mano.

**Forma de una respuesta buena:**

```json
{ "data": { }, "message": "texto opcional" }
```

Algunos endpoints de listado regresan solo `data`, sin `message`.

**Forma de un error:**

```json
{ "message": "Descripción del problema.", "errors": { "campo": ["mensaje"] } }
```

`errors` solo viene en errores de validación (422).

**Códigos que va a ver la app:**

| Código | Qué significa |
|---|---|
| 200 / 201 | Todo bien |
| 401 | No hay token, o ya no sirve. Hay que volver a iniciar sesión |
| 403 | El rol no tiene permitido ese endpoint |
| 404 | No existe **o no es tuyo**. Ver la nota de abajo |
| 422 | Validación: revisar `errors` |
| 429 | Demasiadas peticiones seguidas. Hay límite en el login (20 por minuto), en reenviar la confirmación (4 cada 10 minutos) y en los pagos con Mercado Pago (10 por minuto) |
| 500 | Error del servidor. Solo trae `{ "message": "Ocurrió un error en el servidor. Intenta de nuevo más tarde." }`, nunca el detalle técnico (TG-224) |

**Dos reglas invisibles pero importantes:**

1. **Todo se filtra por distribuidora automáticamente.** La app nunca manda un `distribuidora_id`: el servidor lo deduce del usuario autenticado. No hay forma de pedir datos de otra distribuidora.
2. **Un revendedor o cliente directo solo ve lo suyo.** Si pide un pedido o un vale de otra persona, la respuesta es **404**, no 403 — a propósito, para no confirmarle siquiera que ese registro existe.

**Dos 403 que no son de rol y la app tiene que saber distinguir:**

| Cuándo | Respuesta | Qué hace la app |
|---|---|---|
| La cuenta tiene contraseña temporal (TG-193) | **403** con `debe_cambiar_password: true` | Mandar a la pantalla de cambio de contraseña. Mientras tanto solo responden `auth/me`, `auth/logout`, `perfil` y `perfil/password` |
| El correo no está confirmado y se quiere enviar un pedido o iniciar un pago (TG-189) | **403** con `correo_sin_verificar: true` | Mostrar el `message` y ofrecer `auth/reenviar-verificacion`. El resto de la app sigue funcionando |

Los dos traen su `message` en español, listo para mostrar.

---

## 2. Autenticación

### POST `/api/auth/login`

Sin token. Es el único endpoint público que usa la app.

**Entrada**

```json
{ "email": "maria@ejemplo.com", "password": "secreto123" }
```

**Salida (200)**

```json
{
  "data": {
    "token": "12|abcdef...",
    "token_type": "Bearer",
    "usuario": {
      "id": 7,
      "nombre": "María López",
      "email": "maria@ejemplo.com",
      "telefono": "9631234567",
      "estado": "activo"
    },
    "rol": "revendedor",
    "distribuidora_id": 1,
    "legales_aceptados": true,
    "debe_cambiar_password": false,
    "email_verificado": true
  },
  "message": "Inicio de sesión exitoso."
}
```

Además de `usuario`, `rol` y `distribuidora_id`, la sesión trae tres banderas que la app tiene que mirar al entrar:

| Campo | Qué significa |
|---|---|
| `legales_aceptados` (E1-04) | `false` si todavía no acepta el aviso de privacidad **y** los términos. La app muestra la pantalla de aceptación y llama a `auth/aceptar-legales` |
| `debe_cambiar_password` (TG-193) | `true` si le dieron una contraseña temporal. La app manda a cambiarla; el servidor bloquea casi todo lo demás con un 403 |
| `email_verificado` (TG-189) | `false` si no ha abierto el enlace de confirmación. No bloquea entrar, pero sí enviar pedidos y pagar |

`rol` en una respuesta exitosa es `revendedor` o `cliente_directo` (o `null`, ver la nota de abajo). El personal interno no puede entrar por aquí: ver errores.

**Errores**

- **422** con `errors.email` si las credenciales son incorrectas o la cuenta no está activa. El mensaje que hay que mostrarle al usuario viene ahí, no en `message`.
- **422** con `errors.email` = `"Esta aplicación es solo para revendedores y clientes directos."` si el rol es `admin_general`, `admin_distribuidora` o `empleado` (TG-93). El personal interno entra por el panel web, que tiene su propio login. En este caso **no se crea token**.
- **422** con `errors.email` si su distribuidora está **pendiente** de aprobación (TG-220). Pasa cuando alguien pidió su alta en el formulario público `/solicitar-alta` y el admin general todavía no la revisa. El mensaje es `"La solicitud de Calzados Ramírez está en revisión. Te avisaremos por correo cuando sea aprobada."`. **No se crea token.** Deja de salir en cuanto la aprueban.
- **422** con `errors.email` si su distribuidora está **suspendida** o **rechazada** (TG-196). El mensaje dice cuál es y que su información se conserva; por ejemplo: `"Calzados Ramírez está suspendida por ahora, así que no puedes usar la app con ella. Tu cuenta y tu información se conservan; intenta más tarde."`. **No se crea token.** Si un revendedor está afiliado a otra distribuidora que sí opera, entra con esa.

> Si `rol` o `distribuidora_id` salen en `null` para un revendedor o cliente directo, es que su cuenta no está bien ligada. Se arregla del lado del panel web (E3-07).

### GET `/api/auth/me`

Requiere token. Sin cuerpo. (TG-140)

Para **recuperar la sesión al abrir la app**: la app solo guarda el token, y con él le pregunta al servidor quién es el usuario. No se guardan rol ni distribuidora en el teléfono, porque podrían quedar viejos (por ejemplo, si un admin suspende a alguien).

**Salida (200)** — lo mismo que el login, pero **sin** `token` ni `token_type`:

```json
{
  "data": {
    "usuario": { "id": 7, "nombre": "María López", "email": "maria@ejemplo.com", "telefono": "9631234567", "estado": "activo" },
    "rol": "revendedor",
    "distribuidora_id": 1,
    "legales_aceptados": true,
    "debe_cambiar_password": false,
    "email_verificado": true
  }
}
```

Las tres banderas son las mismas del login y se vuelven a mirar aquí: por eso la app recupera la sesión con `auth/me` en vez de guardarlas en el teléfono.

**Errores**

- **401** si no hay token, si ya se cerró sesión con él, o si la cuenta se desactivó después del login.
- **401** si el token es de personal interno (`admin_general`, `admin_distribuidora`, `empleado`), igual que el rechazo del login (TG-93).
- **401** con `message` = el mismo aviso del login si su distribuidora se **suspendió** o **rechazó** después del login (TG-196).

En los tres casos de rechazo (cuenta desactivada, personal interno o distribuidora sin operar) el servidor además revoca el token. En todos los 401 la app debe regresar a la pantalla de login.

> **Suspendido puede ser en dos niveles, y responden distinto:**
> - La **cuenta** inactiva (`usuarios.estado`) → **401**.
> - Solo la **afiliación** suspendida (`revendedor_distribuidora.estado`) → **200**, pero con `rol` y `distribuidora_id` en `null`, igual que el login. El backend ya no le deja ver nada; la app debe tratar `distribuidora_id` en `null` como "sin acceso".
> - La **distribuidora** pendiente, suspendida o rechazada (`distribuidoras.estado`; TG-196 y TG-220) → **401** con el aviso, y el token se revoca. Mientras tanto, cualquier otro endpoint con un token viejo ya no devuelve datos de esa distribuidora. Al reactivarla, el usuario vuelve a entrar con su misma cuenta y todo está como lo dejó.

### POST `/api/auth/logout`

Requiere token.

**Entrada (opcional)**

```json
{ "fcm_token": "token-que-da-firebase" }
```

Al cerrar la sesión, **el celular que se registró con esa sesión deja de recibir push automáticamente**, aunque la app no mande nada (TG-144): el servidor borra el registro del celular junto con el token.

`fcm_token` sigue siendo opcional (TG-136). Ya no hace falta para que el celular deje de recibir avisos; solo cubre celulares registrados antes de TG-144. Mandarlo no hace daño. Solo borra el dispositivo si es de ese usuario.

**Salida (200):** `{ "message": "Sesión cerrada correctamente." }`

Invalida el token en el servidor. La app borra su copia local de todos modos, aunque esta llamada falle.

### POST `/api/auth/forgot-password`

Sin token. Manda el correo de recuperación.

**Entrada:** `{ "email": "maria@ejemplo.com" }`

**Salida (200) — siempre la misma, exista o no el correo** (TG-141):

```json
{ "message": "Si el correo pertenece a una cuenta, te llegará un enlace para restablecer tu contraseña." }
```

Es a propósito, por seguridad: si la respuesta cambiara según el correo, cualquiera podría averiguar quién tiene cuenta. También responde igual si se pide el enlace varias veces seguidas. **La app debe mostrar este mensaje tal cual y no intentar deducir si la cuenta existe.**

**Error (422):** solo si el correo viene vacío o mal escrito (`errors.email`). Ese error no revela nada sobre las cuentas.

> En local no se manda correo real: con `MAIL_MAILER=log`, el enlace se escribe en `storage/logs/laravel.log`.

### POST `/api/auth/reset-password`

Sin token.

**Entrada**

```json
{
  "email": "maria@ejemplo.com",
  "token": "el-token-que-llegó-por-correo",
  "password": "nuevaClave123",
  "password_confirmation": "nuevaClave123"
}
```

La contraseña debe tener mínimo 8 caracteres y coincidir con su confirmación.

**Error (422):** si el enlace no sirve, por cualquier motivo, siempre el mismo:

```json
{ "message": "El enlace no es válido o ya venció. Solicita uno nuevo.", "errors": { "token": ["El enlace no es válido o ya venció. Solicita uno nuevo."] } }
```

No distingue si el correo existe o si el enlace es inventado o vencido, a propósito, para no revelar quién tiene cuenta (mismo criterio que `forgot-password`).

**Al restablecerla se cierran todas las sesiones de esa cuenta** (TG-142): cualquier celular que tuviera la app abierta con esa cuenta va a recibir **401** en su siguiente petición y tiene que volver a iniciar sesión. Es a propósito: quien restablece puede sospechar que alguien más conoce su contraseña.

### POST `/api/auth/aceptar-legales`

Requiere token. (E1-04)

Registra que esta cuenta aceptó el aviso de privacidad y los términos. La app lo llama desde la pantalla de aceptación, que sale cuando `legales_aceptados` viene en `false`.

**Entrada:** se puede mandar **sin cuerpo**. Si no se manda nada, el servidor registra los dos documentos en su versión `1.0`, que es lo que hace la app.

```json
{ "documentos": [ { "tipo_documento": "aviso_privacidad", "version": "1.0" }, { "tipo_documento": "terminos_condiciones", "version": "1.0" } ] }
```

**Salida (201)**

```json
{
  "data": [
    { "tipo_documento": "aviso_privacidad", "version": "1.0", "fecha_aceptacion": "2026-10-11T18:20:00-06:00" },
    { "tipo_documento": "terminos_condiciones", "version": "1.0", "fecha_aceptacion": "2026-10-11T18:20:00-06:00" }
  ],
  "message": "Documentos legales aceptados correctamente."
}
```

Se guarda también la IP desde la que se aceptó. Después de llamarlo, `legales_aceptados` pasa a `true` en `auth/me`.

> El texto de los dos documentos vive en el sitio web: `/aviso-privacidad` y `/terminos`. La app los abre de ahí; no hay endpoint que devuelva el texto.

### POST `/api/auth/reenviar-verificacion`

Requiere token. Máximo 4 cada 10 minutos (si no, **429**). (TG-189)

Vuelve a mandar el correo de confirmación de la cuenta. Sin cuerpo.

**Salida (200) — siempre la misma, esté o no confirmado:**

```json
{ "message": "Si tu correo está sin confirmar, te mandamos un enlace nuevo. Revisa tu bandeja y la carpeta de spam." }
```

Es a propósito, igual que en `forgot-password`: la respuesta no dice si el correo ya estaba confirmado. Si ya lo estaba, no se manda nada.

---

## 3. Catálogo

### GET `/api/catalogo`

Roles: `admin_distribuidora`, `empleado`, `revendedor`, `cliente_directo`.

Sin parámetros. Regresa los productos **publicados** de las campañas **activas** de la distribuidora del usuario.

**Salida (200)**

```json
{
  "data": [
    {
      "id": 12,
      "producto": {
        "id": 4,
        "modelo": "MOD-01",
        "nombre": "Botín casual",
        "marca":     { "id": 1, "nombre": "Flexi" },
        "linea":     { "id": 2, "nombre": "Dama" },
        "categoria": { "id": 3, "nombre": "Botines" }
      },
      "codigo_catalogo": "CAT-001",
      "precio_minorista_sugerido": 800.0,
      "precio_mayorista": 500.0,
      "imagenes": [],
      "variantes": [
        {
          "variante_id": 9,
          "sku": "SKU-9",
          "talla": "24",
          "color": "Negro",
          "nombre_color_comercial": "Negro humo",
          "disponibilidad": "disponible"
        }
      ]
    }
  ]
}
```

**Cuidado con `precio_mayorista`:** ese campo **solo aparece** para `admin_general`, `admin_distribuidora`, `empleado` y `revendedor`. Para un **cliente directo la llave no viene en el JSON** — no llega en cero ni en null, simplemente no está. En Flutter hay que preguntarlo con cuidado, porque es el precio de costo del revendedor.

**`disponibilidad`, y cuáles se pueden pedir** (TG-214):

| Valor | ¿Se puede agregar a un pedido? |
|---|---|
| `disponible` | Sí |
| `bajo_pedido` | **Sí.** El pedido se surte de fábrica por ciclo, no del mostrador, así que una talla bajo pedido sí se puede pedir. La app puede marcarla distinto, pero no debe esconderla ni bloquearla |
| `no_disponible` | No. La fábrica ya no la da. Si se intenta, **422** con `errors.variante_id` = `"Esa talla no está disponible por ahora."` |

Una variante que ni siquiera tiene disponibilidad registrada en esa temporada se trata como `no_disponible`.

**De dónde sale cada precio** (TG-213, Sprint 4):

- `precio_minorista_sugerido` es el precio del catálogo maestro, **igual para todas las distribuidoras**.
- `precio_mayorista` es el de **esta** distribuidora: sale de su descuento general o del precio propio que le haya puesto a ese producto. Dos distribuidoras pueden mandar números distintos para el mismo producto.

**`linea` sale de la temporada, no del producto** (Sprint 4). El mismo producto puede estar en temporadas de líneas distintas; la línea que llega es la de la temporada en la que se está vendiendo. Para la app no cambia nada: el campo está donde siempre.

---

## 4. Pedidos

Roles: `admin_distribuidora`, `empleado`, `revendedor`, `cliente_directo`.

Un revendedor o cliente directo solo ve y toca **sus** pedidos. El empleado ve todos los de su distribuidora, porque él los captura en el mostrador.

### GET `/api/pedidos`

**Parámetros opcionales:** `estado`, `tipo`. Regresa máximo 100, del más nuevo al más viejo.

**Salida (200):** `{ "data": [ ...pedidos... ] }` — cada uno con la forma de abajo, pero **sin** `lineas`.

### GET `/api/pedidos/{id}`

**Salida (200)**

```json
{
  "data": {
    "id": 15,
    "folio": "PED-20260912-0001",
    "tipo": "revendedor",
    "estado": "borrador",
    "subtotal": 1600.0,
    "total": 1600.0,
    "sucursal_id": 1,
    "propietario": { "tipo": "revendedor", "id": 3, "nombre": "María López" },
    "pagado": 0.0,
    "pagado_con_vales": 0.0,
    "saldo": 1600.0,
    "anticipo_requerido": 200.0,
    "anticipo_pagado": 0.0,
    "anticipo_pendiente": 200.0,
    "ciclo_compra_id": null,
    "fecha_colocacion": null,
    "fecha_entrega": null,
    "fecha_limite_recoleccion": null,
    "observaciones": null,
    "capturado_por_staff_id": null,
    "lineas": [
      {
        "id": 40,
        "producto_nombre": "Botín casual",
        "modelo": "MOD-01",
        "talla": "24",
        "color": "Negro",
        "cantidad": 2,
        "precio_unitario": 800.0,
        "subtotal": 1600.0,
        "anticipo_requerido": 200.0,
        "estado_surtido": "pendiente",
        "variante_id": 9,
        "producto_campana_id": 12,
        "bajo_pedido": false,
        "cambio": { "puede_solicitar": false, "vence_el": null, "motivo": "El pedido todavía no se entrega." }
      }
    ],
    "created_at": "2026-09-12T13:40:00-06:00"
  }
}
```

**`bajo_pedido` en cada línea** (TG-266): `true` si esa talla está **hoy** «bajo pedido» en su temporada (la misma `disponibilidad` del catálogo, sección 3). Es el estado actual, no el que tenía al pedirse: si la fábrica la cambia, cambia aquí también. La app lo usa para marcar la línea («Bajo pedido: se encarga a la fábrica y tarda más en llegar»); no cambia montos ni lo que se puede hacer con el pedido.

**`fecha_limite_recoleccion`** (TG-125): hasta cuándo puede recogerse el pedido (ISO 8601 con zona). Se llena al marcarlo «listo para entrega» con los `dias_maximos_recoleccion` de la distribuidora; antes es `null`. Si pasa la fecha y nadie lo recoge, un proceso diario lo pasa a `vencido_recoleccion` (ver `docs/vencer-recoleccion.md`). La app lo muestra como «Recoge antes del dd/mm/aaaa».

**Los montos del pedido** (y por qué el anticipo puede no ser lo que se espera):

| Campo | Qué es |
|---|---|
| `pagado` | Lo que ya se cobró y quedó aplicado. Un pago `pendiente` de Mercado Pago **no** cuenta aquí |
| `pagado_con_vales` | Cuánto de ese `pagado` vino de vales |
| `saldo` | Lo que falta: `total` menos `pagado` |
| `anticipo_requerido` | Suma del anticipo de cada línea |
| `anticipo_pagado` / `anticipo_pendiente` | Cuánto del anticipo ya entró y cuánto falta |

Dos reglas del anticipo que la app debe reflejar tal cual (TG-215):

1. **Nunca es mayor que el precio del par.** Si un par cuesta $80 y la distribuidora pide $100 de anticipo, el anticipo de ese par es $80. Se mide contra el precio que de verdad se cobra.
2. **El cliente mayorista no da anticipo:** en un pedido de tipo `revendedor`, `anticipo_requerido` siempre es **0**. Paga el total en mostrador o con Mercado Pago.

**Qué pasa con un pedido que no alcanzó a pagar su anticipo** (E8-03): al cerrar el ciclo, el pedido no se pierde ni se cancela; pasa al ciclo siguiente y su `ciclo_compra_id` cambia. Al dueño le llega una notificación en la bandeja (y push, si tiene el celular registrado) avisando que su pedido se movió. La app no tiene que hacer nada: basta con volver a leer el pedido.

`capturado_por_staff_id` viene en **null** cuando el pedido lo hizo el propio cliente desde la app. Si trae un número, lo capturó un empleado.

Estados posibles: `borrador`, `colocado`, `en_revision`, `confirmado`, `parcialmente_disponible`, `rechazado`, `incluido_en_ciclo`, `solicitado_fabrica`, `en_transito`, `recibido_distribuidora`, `listo_entrega`, `entregado`, `no_surtido`, `vencido_recoleccion`, `descartado`.

**Pago con Mercado Pago del cliente mayorista** (TG-229). El pedido también trae estos campos (el ejemplo de arriba no los muestra todos):

```json
{
  "saldo": 1600.0,
  "puede_pagar_anticipo_mercado_pago": false,
  "puede_pagar_saldo_mercado_pago": false,
  "pago_mercado_pago_pendiente": false,
  "pago_mercado_pago_pendiente_tipo": null,
  "puede_pagar_total_mercado_pago": true
}
```

- `puede_pagar_anticipo_mercado_pago` (TG-268): **true** si el pedido es de cliente directo, ya se envió, no está cerrado y queda anticipo pendiente (y saldo). Con **true** la app muestra "Pagar anticipo con Mercado Pago". Es la misma regla que aplica `POST .../anticipo/mercado-pago`. Para el cliente mayorista siempre es **false**.
- `puede_pagar_saldo_mercado_pago` (TG-227): **true** si el pedido es de cliente directo, el anticipo ya está cubierto, queda saldo y el pedido ya llegó a la distribuidora (`recibido_distribuidora` o `listo_entrega`). Con **true** la app muestra "Pagar saldo con Mercado Pago".
- `puede_pagar_total_mercado_pago`: **true** si el pedido es de cliente mayorista (`tipo` = `revendedor`), ya se envió y todavía no se entrega (estado de `colocado` a `listo_entrega`) y `saldo` es mayor a 0. Con **true** la app muestra el botón "Pagar con Mercado Pago". No revisa si la distribuidora tiene Mercado Pago conectado: eso lo dice el error al intentarlo (ver abajo). Para un pedido de cliente directo siempre es **false**.
- `pago_mercado_pago_pendiente`: **true** si hay un pago con Mercado Pago esperando confirmación. Mientras esté pendiente **no cuenta** en `saldo`.
- `pago_mercado_pago_pendiente_tipo`: de qué es ese pago pendiente: `anticipo` o `saldo_pedido` (cliente directo; la app verifica con `.../anticipo/...` o `.../saldo/...`) o `total_revendedor` (cliente mayorista). `null` si no hay. Si es `total_revendedor`, la app muestra "Verificar pago" y llama a `.../total/mercado-pago/verificar`.

`pago_mercado_pago_pendiente` y `pago_mercado_pago_pendiente_tipo` vienen en `GET /api/pedidos/{id}` y en la respuesta de `verificar`, pero **no** en el listado `GET /api/pedidos`. Los tres `puede_pagar_*_mercado_pago` vienen en los tres.

### POST `/api/pedidos`

Crea el pedido en estado `borrador`.

**Entrada**

```json
{
  "tipo": "revendedor",
  "propietario_id": 3,
  "sucursal_id": 1,
  "observaciones": "texto opcional"
}
```

> **Importante para la app:** cuando quien crea el pedido es un **revendedor o cliente directo**, el servidor **ignora** `tipo` y `propietario_id` y los saca del usuario autenticado. Aunque la app mande cualquier cosa ahí, el pedido siempre queda a nombre de quien inició sesión. Se mandan porque el mismo endpoint lo usa el empleado desde la web, que sí elige a nombre de quién captura.

**Salida (201):** `{ "data": { ...pedido... }, "message": "Pedido borrador creado correctamente." }`

### POST `/api/pedidos/{id}/lineas`

**Entrada**

```json
{
  "producto_campana_id": 12,
  "variante_id": 9,
  "cantidad": 2,
  "precio_unitario": 800.0
}
```

`precio_unitario` es opcional: si no se manda, el servidor usa el del catálogo.

**Salida (201):** el pedido completo, ya recalculado.

### DELETE `/api/pedidos/{pedidoId}/lineas/{lineaId}`

**Salida (200):** `{ "data": { ...pedido... }, "message": "Línea eliminada del pedido." }`

### POST `/api/pedidos/{id}/enviar`

Sin cuerpo. Saca el pedido de `borrador` y lo coloca.

**Salida (200):** `{ "data": { ...pedido... }, "message": "Pedido enviado correctamente." }`

**Error (403) si el correo no está confirmado** (TG-189), para cliente y cliente mayorista:

```json
{ "message": "Confirma tu correo para poder enviar pedidos. Te mandamos un enlace cuando te dieron la cuenta; si no lo encuentras, pide otro desde tu perfil.", "correo_sin_verificar": true }
```

Armar el borrador y agregarle líneas **no** pide el correo confirmado: solo enviarlo y pagar. Es a propósito, para no toparse con un muro antes de entender qué se pide. Desde la app se pide un enlace nuevo con `auth/reenviar-verificacion`.

### POST `/api/pedidos/{id}/total/mercado-pago`

Rol: **solo `revendedor`** (cliente mayorista), y solo sobre **sus** pedidos. Límite: **10 por minuto** (cada llamada sale a Mercado Pago). (TG-229)

Sin cuerpo. Prepara el pago con Mercado Pago (Checkout Pro) por **todo lo que falta** del pedido, al precio que ya tiene el pedido. El dinero va a la cuenta de Mercado Pago de la distribuidora.

**Cuándo se puede:** desde que el pedido se envía hasta que está listo para entrega: `colocado`, `en_revision`, `confirmado`, `parcialmente_disponible`, `incluido_en_ciclo`, `solicitado_fabrica`, `en_transito`, `recibido_distribuidora`, `listo_entrega`. Siempre se cobra el **saldo completo**: no hay pagos parciales ni anticipo aparte. Es lo mismo que dice `puede_pagar_total_mercado_pago`.

**Salida (201)**

```json
{
  "data": {
    "pago_id": 31,
    "folio": "PAG-20261008-0004",
    "tipo": "total_revendedor",
    "monto": 1600.0,
    "moneda": "MXN",
    "preferencia_id": "123456789-abcd-...",
    "init_point": "https://www.mercadopago.com.mx/checkout/v1/redirect?pref_id=...",
    "vence_at": "2026-10-09T13:40:00-06:00",
    "reutilizado": false
  },
  "message": "Abre el enlace para pagar tu pedido con Mercado Pago."
}
```

- La app abre `init_point` en el navegador. Al terminar, Mercado Pago regresa al usuario y la app llama a `verificar`.
- **Un solo enlace vivo por pedido.** Si ya hay uno vigente por el mismo monto, se regresa ese con **200** y `reutilizado: true` (no se crea otro). Si el monto cambió (por ejemplo, el mostrador cobró una parte), el enlace viejo se cancela y se crea uno nuevo por lo que falta.
- El enlace dura 24 horas (`vence_at`).
- Mientras el pago esté pendiente, `saldo` no cambia.

**Errores** — todos con `{ "message": "..." }` en español, listo para mostrar:

| Código | Cuándo | `message` |
|---|---|---|
| 401 | Sin token o ya no sirve | `"Tu sesión no es válida o expiró. Inicia sesión de nuevo."` |
| 403 | El rol no es `revendedor` (cliente directo o personal) | `"No tienes permiso para realizar esta acción."` |
| 404 | El pedido no existe o no es suyo | `"No se encontró lo que buscas."` |
| 422 | El pedido sigue en `borrador` | `"Envía tu pedido antes de pagarlo."` |
| 422 | El pedido ya se entregó o se cerró | `"Este pedido ya no admite pagos."` |
| 422 | Ya no debe nada | `"Este pedido ya no tiene saldo pendiente."` |
| 422 | El pedido no es de cliente mayorista | `"Este pago con Mercado Pago es para pedidos de cliente mayorista."` |
| 422 | La distribuidora no tiene Mercado Pago conectado | `"Esta distribuidora todavía no recibe pagos con Mercado Pago. Puedes pagar en mostrador."` |
| 422 | La conexión de la distribuidora venció | `"La distribuidora necesita volver a conectar su cuenta de Mercado Pago. Mientras tanto, puedes pagar en mostrador."` |
| 422 | Otra petición está creando el enlace en este momento | `"Ya estamos preparando tu pago. Espera unos segundos e intenta de nuevo."` |
| 422 | Mercado Pago no aceptó crear el pago | `"No se pudo preparar el pago con Mercado Pago. Intenta de nuevo en unos minutos o paga en mostrador."` |
| 429 | Más de 10 llamadas en un minuto | `"Demasiados intentos. Espera un momento e intenta de nuevo."` |
| 503 | Mercado Pago no respondió | `"Mercado Pago no respondió. Intenta de nuevo en unos minutos."` |

> Las rutas del cliente directo (`.../anticipo/mercado-pago` y `.../saldo/mercado-pago`) responden **403** al cliente mayorista, y esta responde 403 al cliente directo.

### POST `/api/pedidos/{id}/total/mercado-pago/verificar`

Mismo rol y mismo límite que el anterior. (TG-229)

Le pregunta a Mercado Pago si ya se pagó. Se llama **al regresar de Mercado Pago** o cuando el usuario toca "Verificar pago". Llamarlo varias veces no duplica nada.

**Entrada (opcional)**

```json
{ "payment_id": "183120559018" }
```

`payment_id` es el que Mercado Pago pone en la dirección de regreso. Si la app lo tiene, lo manda; si no, el servidor lo busca solo. Solo dígitos (máximo 20).

**Salida (200)** — el pedido actualizado (con sus `pagos`), el resultado y el mensaje para el usuario:

```json
{
  "data": { ...pedido... },
  "resultado": "aplicado",
  "message": "Recibimos el pago de tu pedido. ¡Gracias!"
}
```

| `resultado` | `message` | Qué hace la app |
|---|---|---|
| `aplicado` | `"Recibimos el pago de tu pedido. ¡Gracias!"` | Ya cuenta: `saldo` en 0 y `puede_pagar_total_mercado_pago` en false |
| `pendiente` | `"Tu pago todavía no se confirma. Revisa de nuevo en unos minutos."` | Dejar el botón "Verificar pago" |
| `rechazado` | `"Mercado Pago rechazó el pago. Puedes intentarlo de nuevo con otro medio de pago."` | Ofrecer pagar otra vez |
| `vencido` | `"El enlace de pago venció. Genera uno nuevo para pagar tu pedido."` | Ofrecer pagar otra vez |
| `no_cuadra` | `"Mercado Pago tiene un pago que no coincide con este pedido, así que no se aplicó. No vuelvas a pagar: la distribuidora lo revisará contigo."` | **No** ofrecer pagar otra vez |

**Errores**

- **401 / 403 / 404 / 429 / 503**: igual que el endpoint anterior.
- **422** con `errors.payment_id` = `"El número de pago de Mercado Pago solo lleva dígitos."`.
- **422** `"Este pedido no tiene un pago con Mercado Pago por confirmar."` si no hay ningún pago del cliente mayorista pendiente.
- **422** con los mensajes de Mercado Pago no conectado o conexión vencida de la tabla anterior.

> Aunque la app no llame a `verificar`, el pago se aplica solo cuando llega el aviso de Mercado Pago al servidor. `verificar` sirve para que el usuario vea el resultado en cuanto regresa.

---

### POST `/api/pedidos/{id}/anticipo/mercado-pago` y `/api/pedidos/{id}/saldo/mercado-pago`

Rol: **solo `cliente_directo`**, y solo sobre **sus** pedidos. Crear el enlace pide correo verificado. Límite: **10 por minuto**. (TG-226, TG-227; botón en la app desde TG-268)

Sin cuerpo. Igual que el del cliente mayorista: prepara Checkout Pro y regresa la **misma salida** (`pago_id`, `folio`, `tipo` = `anticipo` o `saldo_pedido`, `monto`, `moneda`, `preferencia_id`, `init_point`, `vence_at`, `reutilizado`), 201 o 200 si reutiliza el enlace vivo.

- **Anticipo**: cobra lo que falta del anticipo (nunca más que el saldo). Se puede desde que el pedido se envía hasta que se cierra. Es lo que dice `puede_pagar_anticipo_mercado_pago`.
- **Saldo**: cobra todo el saldo, con el anticipo ya cubierto y el pedido en `recibido_distribuidora` o `listo_entrega`. Es lo que dice `puede_pagar_saldo_mercado_pago`.

Errores `422` propios (además de los de conexión de Mercado Pago, 401/403/404/429/503 de la tabla anterior):

| Cuándo | `message` |
|---|---|
| El pedido no es de cliente directo | `"El pago con Mercado Pago es para pedidos de cliente directo."` |
| Anticipo de un pedido en borrador | `"Envía tu pedido antes de pagar el anticipo."` |
| Pedido cerrado | `"Este pedido ya no admite pagos."` |
| Ya no queda anticipo | `"Este pedido ya no tiene anticipo pendiente."` |
| Saldo con anticipo pendiente | `"Primero paga tu anticipo; después podrás pagar el saldo."` |
| Saldo antes de que llegue el pedido | `"Podrás pagar el saldo con Mercado Pago cuando tu pedido llegue a la distribuidora."` |
| Ya no queda saldo | `"Este pedido ya no tiene saldo pendiente."` |

> **Iniciar un pago pide el correo confirmado** (TG-189). Los tres endpoints que crean un pago —`.../anticipo/mercado-pago`, `.../saldo/mercado-pago` y `.../total/mercado-pago`— responden **403** con `correo_sin_verificar: true` si la cuenta no ha confirmado su correo. Los tres `verificar` **no** lo piden, a propósito: solo le preguntan a Mercado Pago si un pago que la persona ya hizo entró, y bloquearlos podría perder la confirmación de dinero ya pagado.

### POST `/api/pedidos/{id}/anticipo/mercado-pago/verificar` y `/api/pedidos/{id}/saldo/mercado-pago/verificar`

Igual que `.../total/mercado-pago/verificar` (mismo rol que su enlace, misma entrada opcional `payment_id`, misma salida `data` + `resultado` + `message`). Los mensajes hablan de "tu anticipo" o "tu saldo", por ejemplo `"Recibimos tu anticipo. ¡Gracias!"`.

> **Cómo lo usa la app (TG-268):** abre `init_point` fuera de la app (navegador o app de Mercado Pago). Al volver a la app llama a `verificar` sola, y deja el botón "Verificar pago" mientras `pago_mercado_pago_pendiente` sea true. No hay enlace de regreso directo a la app: la página `/mercado-pago/retorno` le pide a la persona regresar a la app.

---

## 5. Vales

Roles: `admin_distribuidora`, `empleado`, `revendedor`, `cliente_directo` — cada quien solo ve los suyos.

> **Emitir un vale NO lo puede hacer la app.** `POST /api/vales` está reservado a `admin_distribuidora` y `empleado`, porque emitir es entregar saldo. La app solo consulta y aplica.

### GET `/api/vales`

**Parámetro opcional:** `estado`.

**Salida (200)**

```json
{
  "data": [
    {
      "id": 5,
      "folio": "VAL-0005",
      "monto_original": 500.0,
      "saldo_actual": 300.0,
      "fecha_emision": "2026-09-01T10:00:00-06:00",
      "fecha_vencimiento": "2026-11-30T10:00:00-06:00",
      "estado": "activo",
      "por_vencer": false,
      "motivo": "Pedido no surtido",
      "propietario": { "tipo": "revendedor", "id": 3, "nombre": "María López" },
      "creado_por_staff_id": 2
    }
  ]
}
```

Estados: `activo`, `agotado`, `vencido`, `bloqueado`.

**`por_vencer`** (TG-122): `true` si el vale está `activo`, tiene saldo, todavía no vence y vence dentro del margen de aviso (`VALES_DIAS_AVISO_VENCIMIENTO`, 7 días por omisión; el mismo del aviso de TG-242). La app lo marca como «Vence pronto». Lo calcula el servidor para que el margen se cambie en un solo lugar.

### POST `/api/vales/{id}/aplicar`

**Entrada** — se manda `pedido_id` **o** `venta_directa_id`, nunca los dos:

```json
{ "monto": 100.0, "pedido_id": 15 }
```

Si el monto pedido es mayor al saldo, se aplica el saldo disponible, no truena.

**Salida (200):** `{ "data": { ...vale actualizado... }, "message": "Vale aplicado correctamente." }`

**Errores (422):** el vale no está activo, no tiene saldo, está vencido, o no pertenece al mismo dueño que el pedido.
**Error (404):** el vale es de otra persona.

---

## 6. Notificaciones (bandeja dentro de la app)

Roles: `admin_distribuidora`, `empleado`, `revendedor`, `cliente_directo`. Cada quien ve solo las suyas.

### GET `/api/notificaciones`

**Parámetro opcional:** `solo_no_leidas=true`. Regresa máximo 50, de la más nueva a la más vieja.

**Salida (200)**

```json
{
  "data": [
    {
      "id": 30,
      "tipo": "pedido_estado",
      "grupo": "pedidos",
      "titulo": "Tu pedido está listo",
      "mensaje": "El pedido PED-20260912-0001 ya está listo para entrega.",
      "leida": false,
      "leida_at": null,
      "entidad_tipo": "pedido",
      "entidad_id": 15,
      "created_at": "2026-09-12T14:00:00-06:00"
    }
  ]
}
```

`entidad_tipo` y `entidad_id` sirven para que al tocar la notificación la app abra la pantalla correspondiente.

`grupo` (TG-196, campo nuevo, solo lectura) agrupa las notificaciones como la campana del panel: `pedidos` (Pedidos y entregas: `pedido_estado`, `pedido_llegada`, `pedido_anticipo_pendiente`), `pagos` (Pagos y vales: `pago_mercado_pago`, `pago_suscripcion`, `vale_por_vencer`), `promociones` (tipos que empiecen con `promo` o `campana`) y `generales` (todo lo demás). El mapa vive en `app/Support/GruposNotificacion.php`. La app puede ignorarlo sin romper nada.

### POST `/api/notificaciones/{id}/marcar-leida`

Sin cuerpo.

**Salida (200):** `{ "data": { ...notificación... }, "message": "Notificación marcada como leída." }`
**Error (404):** la notificación es de otro usuario.

### POST `/api/dispositivos-fcm`

Requiere token. (E16-03 / TG-136)

Guarda el token que Firebase le da a este celular, ligado al usuario que inició sesión, para poder mandarle notificaciones push.

**Cuándo llamarlo:** después de iniciar sesión (o de recuperar la sesión con `auth/me`), y otra vez cada que Firebase renueve el token del celular. Llamarlo varias veces con el mismo token no duplica nada.

**Entrada**

```json
{ "token": "token-que-da-firebase", "plataforma": "android" }
```

`plataforma`: `android`, `ios` o `web`. Este sprint siempre es `android`.

**Salida**

- **201** la primera vez que se registra ese celular
- **200** si ya estaba registrado (solo se actualiza `ultimo_uso_at`)

```json
{
  "data": { "id": 3, "plataforma": "android", "ultimo_uso_at": "2026-09-13T18:20:00-06:00" },
  "message": "Dispositivo registrado para notificaciones."
}
```

**Errores:** **422** si falta `token` o `plataforma`, o la plataforma no es válida.

> **Mismo celular, otra cuenta:** el token identifica al celular, no a la persona. Si otra cuenta inicia sesión en ese celular y lo registra, el token pasa a esa cuenta: la anterior deja de recibir ahí sus avisos.

**El celular queda ligado a la sesión con la que se registró** (TG-144). Cuando esa sesión termina, por la razón que sea, el servidor borra el celular solo y deja de mandarle push:

- cerrar sesión,
- restablecer la contraseña con el enlace del correo (se cierran todas las sesiones),
- que la cuenta se desactive,
- cambiar la contraseña desde otro celular (se cierran las demás sesiones).

Por eso **hay que registrar el celular después de cada inicio de sesión**, no solo la primera vez: la sesión nueva es la que queda ligada. No hay endpoint para quitarlo a mano.

---

## 7. Perfil del usuario (E1-05 / TG-110)

Roles: cualquier usuario con token (solo `auth:sanctum`, igual que `auth/me`), incluido un revendedor con la afiliación suspendida.

> **No confundir** con `/api/distribuidora/perfil`, que es el perfil de la distribuidora y solo lo usa su admin. Aquí no hay `{id}`: cada quien solo ve y edita su propia cuenta, la del token.

### GET `/api/perfil`

**Salida (200):** el mismo objeto `usuario` del login (con el nombre y el teléfono del contacto; ver la nota de abajo).

```json
{
  "data": {
    "id": 7,
    "nombre": "María López",
    "email": "maria@ejemplo.com",
    "telefono": "9631234567",
    "estado": "activo"
  }
}
```

### PATCH `/api/perfil`

**Entrada**

```json
{ "nombre": "María López Ruiz", "telefono": "9630001111" }
```

- `nombre`: obligatorio, máximo 150.
- `telefono`: opcional, máximo 30. Vacío o solo espacios se guarda como `null`.
- El **correo no se cambia** aquí: si se manda `email`, se ignora.

**De dónde salen el nombre y el teléfono** (TG-216, Sprint 4). Para un cliente o un cliente mayorista, el nombre y el teléfono viven **solo en su registro de contacto**, que es el mismo renglón que edita el panel web. Ya no se guardan en dos lugares ni hay que sincronizarlos: la app y el panel escriben en el mismo sitio. El personal (admins y empleados) no tiene registro de contacto, así que para ellos siguen en su cuenta.

Esto vale también para el `usuario.nombre` y el `usuario.telefono` que vienen en `auth/login`, `auth/me` y `GET /api/perfil`: son los del contacto.

Un cliente que le compra a dos distribuidoras edita el registro de la distribuidora con la que inició sesión, que es la misma cuyo catálogo está viendo.

**Salida (200):** `{ "data": { ...usuario... }, "message": "Datos actualizados correctamente." }`
**Error (422):** `errors.nombre` / `errors.telefono`.

### POST `/api/perfil/password`

**Entrada**

```json
{
  "password_actual": "secreto123",
  "password": "nuevaClave123",
  "password_confirmation": "nuevaClave123"
}
```

La nueva sigue las mismas reglas que el cambio por enlace: mínimo 8 caracteres y que coincida con su confirmación.

**Salida (200):** `{ "message": "Contraseña actualizada. Se cerró la sesión en tus otros dispositivos." }`

**Se cierran las demás sesiones:** se borran todos los tokens de la cuenta **menos el que hizo la petición**. Ese teléfono sigue dentro; los demás reciben 401 en su siguiente petición.

**Errores (422):**
- `errors.password_actual` = `"La contraseña actual no es correcta."`
- `errors.password` si es corta o no coincide la confirmación.

---

## 8. Marketplace (directorio público)

### GET `/api/marketplace`

**Sin token.** Lista las distribuidoras **activas** y visibles en el marketplace (E15-01), ordenadas por `nombre_comercial`.

**Parámetro opcional:** `categoria` (id de una categoría del directorio, TG-197). Si no es un número válido: **422** con `errors.categoria` = `"La categoría no es válida."`.

**Salida (200)**

```json
{
  "data": [
    {
      "id": 1,
      "nombre_comercial": "Calzados Ramírez",
      "slug": "calzados-ramirez",
      "url_tienda": "https://footwearpoint-production.up.railway.app/tienda/calzados-ramirez",
      "logotipo_url": null,
      "descripcion_publica": "Distribuidora multimarca de calzado.",
      "telefono_publico": "9631234567",
      "email_publico": "contacto@calzadosramirez.test",
      "direccion_publica": "Av. Central 123, Comitán, Chiapas",
      "url_mapa": "https://www.google.com/maps/search/?api=1&query=Av.%20Central%20123%2C%20Comit%C3%A1n%2C%20Chiapas%2C%20M%C3%A9xico",
      "horario_publico": "Lunes a sábado, 9:00 a 19:00",
      "categorias": [ { "id": 2, "nombre": "Dama" } ]
    }
  ]
}
```

**`slug` y `url_tienda`** (TG-234):

- `url_tienda` es la dirección **completa** (absoluta) de la tienda pública de la distribuidora. **La app la abre tal cual** (navegador o WebView), sin armarla a mano con el `slug`.
- Desde TG-232 (G12/G13), con el dominio de tiendas configurado en el servidor, `url_tienda` es **`https://{subdominio}.footwearpoint.app/`** (el subdominio de la distribuidora o, si no tiene uno válido, su `slug`). Sin ese dominio sigue siendo `…/tienda/{slug}`. La app no tiene que cambiar nada: siempre abre lo que llegue. Las direcciones viejas `/tienda/{slug}` redirigen solas a la nueva.
- `url_tienda` viene en **`null`** si la distribuidora no está activa o su `slug` no es válido. En ese caso la app **no muestra** el botón "Ver tienda". Hoy el marketplace solo lista activas, así que lo normal es que traiga dirección, pero la app debe aguantar el `null`.
- `slug` es el identificador de la tienda (por ejemplo, `calzados-ramirez`). Sirve para identificarla, **no** para armar la dirección.

**`url_mapa`** (TG-269):

- Dirección de Google Maps que **busca la ubicación** de la distribuidora ("Ver en mapa"). La app la abre tal cual (navegador o app de mapas).
- Se arma con `direccion_publica` o, si viene vacía, con la dirección de la sucursal principal; se agrega ", México" si no lo trae.
- Viene en **`null`** si no hay ninguna dirección. En ese caso la app **no muestra** "Ver en mapa".

### GET `/api/marketplace/categorias`

**Sin token.** Las categorías del directorio que tienen al menos una distribuidora visible, por nombre (TG-197). Sirven para el filtro de la pantalla del marketplace.

**Salida (200)**

```json
{ "data": [ { "id": 2, "nombre": "Dama", "total_distribuidoras": 3 } ] }
```

El `id` es el que se manda luego como `?categoria=` en `GET /api/marketplace`.

---

## 9. Clientes del cliente mayorista (E11-01)

Roles: solo `revendedor` (en pantalla, **cliente mayorista**). Son sus propios clientes, los que le compran a él; FootwearPoint no los ve ni los comparte con la distribuidora.

Cada quien solo toca los suyos: uno ajeno responde **404**.

### GET `/api/revendedor/clientes-privados`

**Salida (200):** `{ "data": [ { "id": 4, "nombre": "Ana Pérez", "telefono": "9631112233", "referencia": "La del mercado" } ] }`

### POST `/api/revendedor/clientes-privados`

**Entrada:** `{ "nombre": "Ana Pérez", "telefono": "9631112233", "referencia": "La del mercado" }`

`nombre` es obligatorio; `telefono` y `referencia` son opcionales.

**Salida (201):** `{ "data": { ...cliente... }, "message": "Cliente agregado." }`

### PUT `/api/revendedor/clientes-privados/{id}`

Mismos campos que al crear. **Salida (200)** con el cliente actualizado.

### DELETE `/api/revendedor/clientes-privados/{id}`

**Salida (200)** con su mensaje. **404** si no es suyo.

---

## 10. Solicitudes de cambio de producto (TG-254, E12-01)

Roles: `admin_distribuidora`, `empleado`, `revendedor`, `cliente_directo`. Cada cliente solo ve y pide lo suyo (lo ajeno responde 404); el personal ve todo lo de su distribuidora.

Reglas: el plazo es `configuracion_distribuidora.dias_solicitud_cambio` (por defecto 12), igual para clientes directos y mayoristas, en días naturales desde la entrega del pedido (`fecha_entrega`) o la venta de mostrador (`fecha_venta`); el último día cuenta completo (hasta 23:59:59, hora de México). Una solicitud cubre la línea completa y solo puede haber una abierta por línea (una `rechazada` o `cerrada` ya no bloquea). Queda en `solicitada`; autorizar, rechazar y emitir el vale es de otra tarea. No hay devoluciones en efectivo.

### `POST /api/solicitudes-cambio`

```json
{ "pedido_detalle_id": 123, "motivo": "No me quedó la talla" }
```

Exactamente uno de `pedido_detalle_id` (pedido entregado) o `venta_detalle_id` (venta de mostrador completada y con cliente; solo personal). `motivo` obligatorio, máximo 300.

`201`:

```json
{
  "data": {
    "id": 1, "estado": "solicitada", "origen": "pedido",
    "pedido_detalle_id": 123, "venta_detalle_id": null, "pedido_id": 45,
    "producto": { "nombre": "Bota", "modelo": "BC-1", "talla": "25", "color": "Café", "cantidad": 2 },
    "propietario": { "tipo": "cliente_directo", "id": 9 },
    "motivo": "No me quedó la talla", "resolucion": null,
    "fecha_entrega_original": "2026-10-01T10:00:00-06:00",
    "fecha_solicitud": "2026-10-05T12:00:00-06:00",
    "fecha_limite_solicitud": "2026-10-13T23:59:59-06:00",
    "dias_solicitud_aplicados": 12, "vale_generado_id": null
  },
  "message": "Solicitud de cambio enviada. Si se autoriza, recibirás un vale; no hay devoluciones en efectivo."
}
```

Errores (`{"message": ...}`): `404` línea inexistente o ajena; `409` ya hay una solicitud abierta; `422` validación, pedido no entregado, venta sin cliente o plazo vencido ("El plazo para pedir cambio terminó el 13/10/2026 (12 días desde la entrega).").

### `GET /api/solicitudes-cambio`

`{"data": [ ...mismo objeto... ]}`, las más recientes primero (máximo 200).

### Cambios en `GET /api/pedidos` y `GET /api/pedidos/{id}`

- `fecha_entrega` (ISO 8601 o `null`).
- Cada elemento de `lineas` trae `cambio`:

```json
{ "puede_solicitar": true, "fecha_limite": "2026-10-13T23:59:59-06:00", "solicitud": null }
```

`fecha_limite` es `null` si el pedido no está entregado; `solicitud` es `{ "id", "estado" }` de la abierta, si la hay. Campos nuevos y opcionales: no rompen a clientes anteriores.

---

## 11. Cambios del Sprint 4

Lo que cambió para quien programa la app. Nada de esto rompe campos que ya existían.

| Qué | Dónde se ve |
|---|---|
| La sesión trae `legales_aceptados`, `debe_cambiar_password` y `email_verificado` | `auth/login` y `auth/me` (sección 2) |
| Aceptar el aviso de privacidad y los términos desde la app | `POST /api/auth/aceptar-legales` (sección 2) |
| Confirmar el correo: 403 con `correo_sin_verificar` al enviar un pedido o iniciar un pago, y cómo pedir otro enlace | Secciones 1, 2 y 4 |
| Contraseña temporal: 403 con `debe_cambiar_password` en casi toda la API | Sección 1 |
| El catálogo es uno solo en dos capas: el precio de menudeo es del catálogo y el de mayoreo es de cada distribuidora; la línea sale de la temporada | Sección 3 |
| `bajo_pedido` **sí** se puede pedir; `no_disponible` no | Sección 3 |
| El anticipo nunca pasa del precio del par, y es 0 para el cliente mayorista | Sección 4 |
| Un pedido que no alcanzó a pagar su anticipo pasa al ciclo siguiente y avisa | Sección 4 |
| El nombre y el teléfono de clientes y clientes mayoristas salen de su contacto, no de la cuenta | Secciones 2 y 7 |
| Una distribuidora **pendiente** de aprobación tampoco deja entrar, igual que suspendida o rechazada | Sección 2 |
| Pagos con Mercado Pago: anticipo y saldo del cliente, total del cliente mayorista | Sección 4 |
| `url_tienda` y `url_mapa` del marketplace | Sección 8 |
| Los clientes del cliente mayorista | Sección 9 |
| Solicitudes de cambio de producto | Sección 10 |

---

## 12. De dónde salió cada cosa

Para verificar o actualizar este documento:

| Sección | Archivos |
|---|---|
| Auth | `app/Http/Controllers/Api/AuthController.php`, `app/Http/Requests/Auth/` |
| Catálogo | `app/Http/Controllers/Api/Catalogo/CatalogoController.php`, `app/Http/Resources/Catalogo/CatalogoResource.php` |
| Pedidos | `app/Http/Controllers/Api/PedidoController.php`, `app/Http/Requests/Pedido/`, `app/Http/Resources/PedidoResource.php` |
| Pago del cliente mayorista con Mercado Pago | `app/Http/Controllers/Api/PagoMercadoPagoController.php`, `app/Services/Pago/CrearPagoPedidoMercadoPagoAction.php`, `app/Services/Pago/VerificarPagoMercadoPagoAction.php`, `app/Services/MercadoPago/MercadoPagoException.php`, `routes/api/pedidos.php` |
| Vales | `app/Http/Controllers/Api/ValeController.php`, `app/Http/Requests/Vale/`, `app/Http/Resources/ValeResource.php` |
| Notificaciones | `app/Http/Controllers/Api/NotificacionController.php`, `app/Http/Resources/NotificacionResource.php` |
| Dispositivos FCM | `app/Http/Controllers/Api/DispositivoFcmController.php`, `app/Services/Notificacion/GestionarDispositivoFcmAction.php` |
| Perfil | `app/Http/Controllers/Api/PerfilUsuarioController.php`, `app/Http/Requests/Perfil/`, `app/Services/Perfil/` |
| Marketplace | `app/Http/Controllers/Api/MarketplaceController.php`, `app/Http/Resources/MarketplaceDistribuidoraResource.php`, `app/Services/Tienda/EnlaceTienda.php`, `routes/api/marketplace.php` |
| Clientes del cliente mayorista | `app/Http/Controllers/Api/ClientePrivadoRevendedorController.php` |
| Correo confirmado y contraseña temporal | `app/Http/Middleware/CorreoVerificado.php`, `app/Http/Middleware/DebeCambiarPassword.php` |
| Quién entra a qué | `routes/api.php` y `routes/api/*.php` |
| Filtro por dueño | `app/Support/PropietarioActual.php` |
| Filtro por distribuidora | `app/Support/Tenant.php`, `app/Models/Scopes/TenantScope.php` |

---

## 13. Endpoints que no usa la app

Existen en `/api` y están probados, pero los consume el panel web, no la app de Flutter. No se documentan a detalle aquí; si algún día la app los necesita, se suben a su propia sección.

**Admin general — el directorio público**

| Ruta | Para qué |
|---|---|
| `GET` y `POST /api/admin/categorias-directorio` | Las categorías del directorio |
| `PUT /api/admin/categorias-directorio/{id}` | Cambiarle el nombre a una |
| `PATCH /api/admin/categorias-directorio/{id}/activar` y `PATCH /api/admin/categorias-directorio/{id}/desactivar` | Prenderla o apagarla |
| `PATCH /api/admin/marketplace/config` | Qué se ve en el directorio |

**Admin general — distribuidoras y planes**

| Ruta | Para qué |
|---|---|
| `GET /api/admin/distribuidoras` | Listarlas con su estado |
| `GET /api/admin/distribuidoras/{id}` | Revisar los datos de una solicitud antes de decidir |
| `POST /api/admin/distribuidoras/{id}/aprobar` | Aprobarla y abrirle su suscripción |
| `POST /api/admin/distribuidoras/{id}/rechazar` | Rechazarla con un motivo |
| `POST /api/admin/distribuidoras/{id}/suspender` y `POST /api/admin/distribuidoras/{id}/reactivar` | Pararla o devolverla a operar |
| `PUT /api/admin/distribuidoras/{id}/categorias-directorio` | Asignarle categorías |
| `POST /api/admin/distribuidoras/{id}/suscripcion` | Asignarle un plan |
| `GET` y `POST /api/admin/planes-suscripcion` | Los planes y sus precios |
| `GET`, `PUT` y `DELETE /api/admin/planes-suscripcion/{id}` | Ver, cambiar o quitar un plan |

**Admin general — el catálogo maestro**

| Ruta | Para qué |
|---|---|
| `GET` y `POST /api/lineas`, `PATCH`, `GET` y `DELETE /api/lineas/{linea}` | Las líneas (las empresas del catálogo) |
| `GET` y `POST /api/marcas`, `PATCH`, `GET` y `DELETE /api/marcas/{marca}` | Las marcas, que viven dentro de cada línea |
| `GET` y `POST /api/categorias-producto`, `PATCH` y `GET /api/categorias-producto/{categoria}` | Las categorías de producto |
| `GET` y `POST /api/productos`, `PATCH`, `GET` y `DELETE /api/productos/{producto}` | Los productos |
| `GET` y `POST /api/variantes`, `PATCH` y `GET /api/variantes/{variante}` | Las tallas y colores de cada producto |
| `GET` y `POST /api/campanas`, `PATCH`, `GET` y `DELETE /api/campanas/{campana}` | Las temporadas de cada línea |
| `GET` y `POST /api/producto-campana`, `PATCH` y `GET /api/producto-campana/{productoCampana}` | Qué producto se vende en qué temporada, con su precio |
| `GET` y `POST /api/producto-campana/{productoCampana}/imagenes` | Sus fotos |
| `DELETE /api/producto-campana/imagenes/{imagen}` y `PATCH /api/producto-campana/imagenes/{imagen}/principal` | Quitar una foto o volverla la principal |
| `GET` y `POST /api/disponibilidad-variante-campana`, `PATCH` y `GET /api/disponibilidad-variante-campana/{disponibilidadVarianteCampana}` | Qué tallas da la fábrica en esa temporada |

**Borrar del catálogo maestro (TG-247).** `DELETE /api/lineas/{linea}`, `DELETE /api/marcas/{marca}`, `DELETE /api/campanas/{campana}` y `DELETE /api/productos/{producto}`. Solo admin general (otro rol: 403). El borrado es definitivo (no hay papelera), por eso se bloquea si algo depende del registro:

| Qué | Se bloquea si tiene |
|---|---|
| Línea | temporadas, distribuidoras que la venden o importaciones de catálogo |
| Marca | productos |
| Temporada | productos publicados o importaciones de catálogo |
| Producto | stock, partidas de pedidos o de ventas de mostrador, precios propios o destacados de alguna distribuidora |

- **Salida (200):** `{ "data": null, "message": "Marca eliminada." }`
- **Bloqueado (409):** `{ "message": "No se puede eliminar la marca: tiene 12 productos. Desactívala o mueve sus productos a otra marca." }`
- Al borrar un producto, la base borra con él sus variantes, publicaciones en temporadas, disponibilidad e imágenes (son del catálogo). Nunca se borran pedidos ni stock de distribuidoras.
- Cada borrado queda en la auditoría (`catalogo.linea.eliminada`, `catalogo.marca.eliminada`, `catalogo.temporada.eliminada`, `catalogo.producto.eliminado`) con los datos que tenía.

**Admin de distribuidora**

| Ruta | Para qué |
|---|---|
| `GET` y `PATCH /api/distribuidora/perfil` | Los datos públicos de la distribuidora |
| `GET` y `PATCH /api/distribuidora/config` | Su configuración (anticipo, descuento de mayoreo, plazos) |
| `GET /api/distribuidora/empleados` y `GET`, `PATCH /api/distribuidora/empleados/{empleado}` | Su personal |
| `GET` y `POST /api/distribuidora/revendedores`, `GET` y `PATCH /api/distribuidora/revendedores/{revendedor}` | Sus clientes mayoristas |
| `GET` y `POST /api/distribuidora/clientes-directos`, `GET` y `PATCH /api/distribuidora/clientes-directos/{cliente}` | Sus clientes |
| `GET` y `POST /api/distribuidora/ciclos-config`, `GET`, `PATCH` y `DELETE /api/distribuidora/ciclos-config/{ciclo}` | Cómo se arman sus ciclos |
| `POST /api/auth/register-empleado` | Dar de alta a un empleado |

**Mostrador y operación (admin de distribuidora y empleado)**

| Ruta | Para qué |
|---|---|
| `GET /api/ciclos/vigente` y `GET /api/ciclos/{id}` | El ciclo de compra en curso |
| `POST /api/ciclos/{id}/cerrar` | Cerrarlo para que ya no entren pedidos |
| `POST /api/ciclos/{id}/solicitar-fabrica` | Mandarlo a fábrica |
| `POST /api/ciclos/{id}/marcar-transito` y `POST /api/ciclos/{id}/marcar-recibido` | Moverlo por sus etapas |
| `POST /api/ciclos/{id}/finalizar` | Darlo por terminado |
| `GET /api/stock`, `POST /api/stock/entradas`, `POST /api/stock/ajustes` | Inventario por sucursal |
| `POST /api/ventas-directas` | Venta de mostrador, con vale opcional (TG-250) |
| `POST /api/vales` | Emitir un vale. La app solo consulta y aplica |
| `POST /api/pedidos/{id}/pagos` | Registrar un pago en mostrador |
| `GET /api/reportes/resumen` | Los números del tablero |
| `GET /api/reportes/pedidos-por-cliente-mayorista` | Quiénes compran más (TG-251) |
| `GET /api/reportes/montos-pendientes` | Lo que falta por cobrar (TG-252) |
| `GET /api/reportes/demanda` | Qué se pide más (TG-253) |

**Sin token, y no son para la app**

| Ruta | Para qué |
|---|---|
| `GET /api/ping` | Comprobar que la API responde |
| `POST /api/webhooks/mercado-pago` | Aviso de Mercado Pago cuando cambia un pago (TG-228) |

> **Acuerdo del equipo:** quien crea un endpoint de `/api` lo documenta. Si no está ni arriba ni en esta lista, la prueba `tests/Feature/Documentacion/ContratoApiCompletoTest.php` falla y dice cuál falta.
