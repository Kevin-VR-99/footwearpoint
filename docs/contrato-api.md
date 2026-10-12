# Contrato de API — app móvil FootwearPoint

Historia **E18-03 (TG-97)**.

Este documento describe los endpoints que consume la app de Flutter: ruta, método, quién puede entrar, qué se manda y qué regresa. Todo está sacado del código real de Laravel (controladores, Form Requests y API Resources), no de suposiciones.

Sirve para que Ailton y Aurelio construyan sus pantallas sin tener que leer el backend ni esperar a que esté terminado.

> **Ojo:** los permisos que se describen aquí incluyen los cambios de la Parte 1 (TG-134), que están en el PR `feature/acceso-multirol-revendedor-cliente`. Antes de que ese PR se mergee, las rutas de catálogo, pedidos, vales y notificaciones todavía rechazan a revendedor y cliente directo.

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
| 500 | Error del servidor. Solo trae `{ "message": "Ocurrió un error en el servidor. Intenta de nuevo más tarde." }`, nunca el detalle técnico (TG-224) |

**Dos reglas invisibles pero importantes:**

1. **Todo se filtra por distribuidora automáticamente.** La app nunca manda un `distribuidora_id`: el servidor lo deduce del usuario autenticado. No hay forma de pedir datos de otra distribuidora.
2. **Un revendedor o cliente directo solo ve lo suyo.** Si pide un pedido o un vale de otra persona, la respuesta es **404**, no 403 — a propósito, para no confirmarle siquiera que ese registro existe.

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
    "distribuidora_id": 1
  },
  "message": "Inicio de sesión exitoso."
}
```

`rol` en una respuesta exitosa es `revendedor` o `cliente_directo` (o `null`, ver la nota de abajo). El personal interno no puede entrar por aquí: ver errores.

**Errores**

- **422** con `errors.email` si las credenciales son incorrectas o la cuenta no está activa. El mensaje que hay que mostrarle al usuario viene ahí, no en `message`.
- **422** con `errors.email` = `"Esta aplicación es solo para revendedores y clientes directos."` si el rol es `admin_general`, `admin_distribuidora` o `empleado` (TG-93). El personal interno entra por el panel web, que tiene su propio login. En este caso **no se crea token**.
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
    "distribuidora_id": 1
  }
}
```

**Errores**

- **401** si no hay token, si ya se cerró sesión con él, o si la cuenta se desactivó después del login.
- **401** si el token es de personal interno (`admin_general`, `admin_distribuidora`, `empleado`), igual que el rechazo del login (TG-93).
- **401** con `message` = el mismo aviso del login si su distribuidora se **suspendió** o **rechazó** después del login (TG-196).

En los tres casos de rechazo (cuenta desactivada, personal interno o distribuidora sin operar) el servidor además revoca el token. En todos los 401 la app debe regresar a la pantalla de login.

> **Suspendido puede ser en dos niveles, y responden distinto:**
> - La **cuenta** inactiva (`usuarios.estado`) → **401**.
> - Solo la **afiliación** suspendida (`revendedor_distribuidora.estado`) → **200**, pero con `rol` y `distribuidora_id` en `null`, igual que el login. El backend ya no le deja ver nada; la app debe tratar `distribuidora_id` en `null` como "sin acceso".
> - La **distribuidora** suspendida o rechazada (`distribuidoras.estado`, TG-196) → **401** con el aviso, y el token se revoca. Mientras tanto, cualquier otro endpoint con un token viejo ya no devuelve datos de esa distribuidora. Al reactivarla, el usuario vuelve a entrar con su misma cuenta y todo está como lo dejó.

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

`disponibilidad` puede ser: `disponible`, `bajo_pedido`, `no_disponible`.

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
    "ciclo_compra_id": null,
    "fecha_colocacion": null,
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
        "producto_campana_id": 12
      }
    ],
    "created_at": "2026-09-12T13:40:00-06:00"
  }
}
```

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

### POST `/api/pedidos/{id}/anticipo/mercado-pago/verificar` y `/api/pedidos/{id}/saldo/mercado-pago/verificar`

Igual que `.../total/mercado-pago/verificar` (mismo rol que su enlace, misma entrada opcional `payment_id`, misma salida `data` + `resultado` + `message`). Los mensajes hablan de "tu anticipo" o "tu saldo", por ejemplo `"Recibimos tu anticipo. ¡Gracias!"`.

> **Cómo lo usa la app (TG-268):** abre `init_point` fuera de la app (navegador o app de Mercado Pago). Al volver a la app llama a `verificar` sola, y deja el botón "Verificar pago" mientras `pago_mercado_pago_pendiente` sea true. No hay enlace de regreso directo a la app: la página `/mercado-pago/retorno` le pide a la persona regresar a la app.

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
      "motivo": "Pedido no surtido",
      "propietario": { "tipo": "revendedor", "id": 3, "nombre": "María López" },
      "creado_por_staff_id": 2
    }
  ]
}
```

Estados: `activo`, `agotado`, `vencido`, `bloqueado`.

### POST `/api/vales/{id}/aplicar`

**Entrada** — se manda `pedido_id` **o** `venta_directa_id`, nunca los dos:

```json
{ "monto": 100.0, "pedido_id": 15 }
```

Si el monto pedido es mayor al saldo, se aplica el saldo disponible, no truena.

**Salida (200):** `{ "data": { ...vale actualizado... }, "message": "Vale aplicado correctamente." }`

**Errores (422):** el vale no está activo, no tiene saldo, está vencido, o no pertenece al mismo dueño que el pedido.
**Error (404):** el vale es de otra persona.

### POST `/api/ventas-directas` — venta de mostrador, con vale opcional (TG-250)

Roles: `admin_distribuidora`, `empleado`. La venta es de contado y descuenta la existencia de inmediato.

**Entrada**

```json
{
  "metodo_pago": "efectivo",
  "cliente_directo_id": 12,
  "vale_folio": "VAL-0005",
  "lineas": [
    { "variante_id": 40, "producto_campana_id": 7, "cantidad": 1 }
  ]
}
```

- `metodo_pago`: `efectivo`, `transferencia`, `tarjeta`, `mercado_pago` u `otro`. Se pide siempre, aunque el vale cubra todo (en ese caso no se usa).
- `cliente_directo_id` (opcional).
- `vale_folio` (opcional, TG-250): folio de un vale **de cliente directo** de la misma distribuidora (sin distinguir mayúsculas).
  - Se descuenta hasta donde alcance su saldo. Si la compra cuesta más, se cobra la diferencia con `metodo_pago`; si cuesta menos, el saldo restante se queda en el mismo vale.
  - Si no se manda `cliente_directo_id`, la venta queda a nombre del dueño del vale.
  - Todo es una sola operación: si algo falla (existencia, vale), no se guarda la venta ni se toca el vale.

**Salida (201)**

```json
{
  "data": {
    "id": 31, "folio": "VD-2026-000031", "cliente_directo_id": 12,
    "subtotal": 899.0, "descuento": 0.0, "total": 899.0,
    "desglose_iva": { "base_gravable": 775.0, "iva": 124.0, "etiqueta_base": "Base gravable", "etiqueta_iva": "IVA (16%)" },
    "vale": { "id": 5, "folio": "VAL-0005", "monto_aplicado": 300.0, "saldo_restante": 0.0, "estado": "agotado" },
    "por_cobrar": 599.0,
    "pago": { "id": 80, "folio": "PG-2026-000080", "metodo": "efectivo", "monto": 599.0, "estado": "aplicado" },
    "lineas": [ ]
  },
  "message": "Venta directa registrada."
}
```

- `vale` es `null` si no se mandó vale.
- `pago` es `null` cuando el vale cubrió todo el total (`por_cobrar` = 0).

**Errores (422)** con `message` listo para mostrar:
- `No existe un vale con el folio X.` (tampoco si es de otra distribuidora)
- `El vale X está vencido.` · `El vale X ya no tiene saldo.` · `El vale X no está activo.`
- `El vale X es de un cliente mayorista; en el punto de venta solo se aceptan vales de clientes directos.`
- `El vale X es de otro cliente. Elige a su dueño o quita el vale.`

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

**Salida (200):** el mismo objeto `usuario` del login.

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

Además de `usuarios`, se actualiza en la misma operación el registro ligado en `revendedores` o `clientes_directos` (lo que ve el empleado en el panel web), para que no se desincronicen.

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

---

## 8.1 Reportes

### GET `/api/reportes/pedidos-por-cliente-mayorista` (TG-251)

Total de pedidos y montos por cliente mayorista (en el código, *revendedor*), para saber quiénes compran más.

Roles: `admin_distribuidora`, y `empleado` **solo con el permiso `reportes.ver_financieros`** (TG-246). Sin el permiso responde **403**.

**Parámetros opcionales (query):**
- `desde`, `hasta`: `AAAA-MM-DD`, días completos. Se filtra por la **fecha de colocación** del pedido.
- `ciclo_id`: solo pedidos de ese ciclo de compra.

**Qué pedidos cuentan:** los de clientes mayoristas desde que se colocan. No cuentan los borradores ni los pedidos `rechazado`, `descartado`, `no_surtido` o `vencido_recoleccion`.

**Salida (200)**

```json
{
  "data": {
    "filtros": { "desde": "2026-09-01", "hasta": "2026-09-30", "ciclo_id": null },
    "filas": [
      {
        "revendedor_distribuidora_id": 3,
        "nombre": "María López",
        "pedidos": 4,
        "monto": 5200.0,
        "pagado": 3000.0,
        "pendiente": 2200.0
      }
    ],
    "totales": { "clientes": 1, "pedidos": 4, "monto": 5200.0, "pagado": 3000.0, "pendiente": 2200.0 }
  }
}
```

- `filas` viene ordenado de mayor a menor `monto`.
- `monto`: suma de los totales de sus pedidos.
- `pagado`: pagos de entrada aplicados más vales aplicados a esos pedidos.
- `pendiente`: lo que falta por pagar de cada pedido (nunca negativo), sumado.

**Errores (422):** fechas con otro formato, o `hasta` anterior a `desde` (`La fecha "hasta" no puede ser anterior a "desde".`).

### GET `/api/reportes/montos-pendientes` (TG-252)

Cuánto falta por cobrar **al día de hoy**, por cliente directo y por cliente mayorista (en el código, *revendedor*), y los vales vigentes.

Roles: `admin_distribuidora`, y `empleado` **solo con el permiso `reportes.ver_financieros`** (TG-246). Sin el permiso responde **403**.

**Parámetro opcional (query):** `ciclo_id`, para ver solo los pedidos de ese ciclo de compra. No hay filtro de fechas: es una foto del momento.

**Qué pedidos cuentan:** los colocados o entregados que todavía tienen saldo mayor a 0. No cuentan los borradores ni los pedidos `rechazado`, `descartado`, `no_surtido` o `vencido_recoleccion`.

**Salida (200)**

```json
{
  "data": {
    "filtros": { "ciclo_id": null },
    "clientes_directos": {
      "filas": [
        { "id": 12, "nombre": "Ana Pérez", "pedidos": 2, "monto": 1800.0, "pagado": 500.0, "anticipo_pendiente": 100.0, "pendiente": 1300.0 }
      ],
      "totales": { "clientes": 1, "pedidos": 2, "monto": 1800.0, "pagado": 500.0, "pendiente": 1300.0, "anticipo_pendiente": 100.0 }
    },
    "revendedores": {
      "filas": [
        { "id": 3, "nombre": "María López", "pedidos": 1, "monto": 5200.0, "pagado": 3000.0, "pendiente": 2200.0 }
      ],
      "totales": { "clientes": 1, "pedidos": 1, "monto": 5200.0, "pagado": 3000.0, "pendiente": 2200.0 }
    },
    "vales_vigentes": {
      "filas": [
        { "id": 5, "folio": "VAL-0005", "propietario": { "tipo": "cliente_directo", "nombre": "Ana Pérez" }, "saldo": 300.0, "fecha_vencimiento": "2026-11-30" }
      ],
      "totales": { "vales": 1, "saldo": 300.0 }
    }
  }
}
```

- En `clientes_directos`, `id` es el del cliente directo; en `revendedores`, `id` es la afiliación (`revendedor_distribuidora_id`).
- Las filas vienen ordenadas de mayor a menor `pendiente`.
- `pagado`: pagos de entrada aplicados más vales aplicados. `pendiente`: lo que falta de cada pedido, sumado.
- `anticipo_pendiente` solo viene en clientes directos: la parte del anticipo que todavía no se cubre.
- Vales vigentes: activos, con saldo y sin vencer, del más próximo a vencer al más lejano. `propietario.tipo` es `cliente_directo` o `revendedor`.

**Error (422):** `ciclo_id` que no es un número entero positivo.

### GET `/api/reportes/demanda` (TG-253)

Productos más solicitados y marcas con más demanda, por temporada (en el código, *campaña*), para orientar las compras a fábrica.

Roles: `admin_distribuidora`, y `empleado` **solo con el permiso `reportes.ver_financieros`** (TG-246). Sin el permiso responde **403**.

**Parámetros opcionales (query):**
- `campana_id`: una temporada.
- `marca_id`: una marca.
- `desde`, `hasta`: `AAAA-MM-DD`, días completos. Se filtra por la **fecha de colocación** del pedido.
- `ciclo_id`: solo pedidos de ese ciclo de compra.

**Qué cuenta:** las piezas de los **pedidos** (no las ventas de mostrador) desde que se colocan, **incluidas las no surtidas** (pedidos `no_surtido` y líneas no surtidas), porque fueron demanda real. No cuentan los borradores ni los pedidos `rechazado`, `descartado` o `vencido_recoleccion`.

**Salida (200)**

```json
{
  "data": {
    "filtros": { "campana_id": null, "marca_id": null, "desde": null, "hasta": null, "ciclo_id": null },
    "por_marca": [
      { "id": 2, "nombre": "Andrea", "piezas": 48, "pedidos": 17, "modelos": 9, "monto": 31200.0 }
    ],
    "productos": [
      { "producto_campana_id": 7, "producto": "Bota Texana", "modelo": "BT-200", "marca": "Andrea", "temporada": "Primavera 2026", "piezas": 12, "pedidos": 6, "monto": 9600.0 }
    ],
    "por_temporada": [
      { "id": 3, "nombre": "Primavera 2026", "linea": "Dama", "piezas": 48, "pedidos": 17, "monto": 31200.0 }
    ],
    "totales": { "piezas": 48, "pedidos": 17, "monto": 31200.0 }
  }
}
```

- Todas las listas vienen ordenadas de mayor a menor `piezas`.
- `productos` trae solo los 20 con más piezas.
- `monto`: suma de los subtotales pedidos (precio por cantidad), no lo cobrado.
- `modelos`: número de productos distintos de esa marca que se pidieron.

**Errores (422):** ids que no son números enteros positivos, fechas con otro formato, o `hasta` anterior a `desde` (`La fecha "hasta" no puede ser anterior a "desde".`).

---

## 9. De dónde salió cada cosa

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
| Quién entra a qué | `routes/api.php` y `routes/api/*.php` |
| Filtro por dueño | `app/Support/PropietarioActual.php` |
| Filtro por distribuidora | `app/Support/Tenant.php`, `app/Models/Scopes/TenantScope.php` |

## Solicitudes de cambio de producto (TG-254, E12-01)

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
