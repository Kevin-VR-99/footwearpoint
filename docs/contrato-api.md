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

En los dos casos de rechazo (cuenta desactivada o personal interno) el servidor además revoca el token. En todos los 401 la app debe regresar a la pantalla de login.

> **Suspendido puede ser en dos niveles, y responden distinto:**
> - La **cuenta** inactiva (`usuarios.estado`) → **401**.
> - Solo la **afiliación** suspendida (`revendedor_distribuidora.estado`) → **200**, pero con `rol` y `distribuidora_id` en `null`, igual que el login. El backend ya no le deja ver nada; la app debe tratar `distribuidora_id` en `null` como "sin acceso".

### POST `/api/auth/logout`

Requiere token.

**Entrada (opcional)**

```json
{ "fcm_token": "token-que-da-firebase" }
```

Si se manda, ese celular **deja de recibir notificaciones push** en la misma llamada (E16-03 / TG-136). La app debe mandarlo siempre que tenga el token de Firebase: si no, el celular seguiría recibiendo avisos de una cuenta que ya cerró sesión. Solo borra el dispositivo si es de ese usuario.

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

**Para quitarlo** no hay endpoint aparte: se manda `fcm_token` al cerrar sesión (ver `POST /api/auth/logout`).

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

## 8. De dónde salió cada cosa

Para verificar o actualizar este documento:

| Sección | Archivos |
|---|---|
| Auth | `app/Http/Controllers/Api/AuthController.php`, `app/Http/Requests/Auth/` |
| Catálogo | `app/Http/Controllers/Api/Catalogo/CatalogoController.php`, `app/Http/Resources/Catalogo/CatalogoResource.php` |
| Pedidos | `app/Http/Controllers/Api/PedidoController.php`, `app/Http/Requests/Pedido/`, `app/Http/Resources/PedidoResource.php` |
| Vales | `app/Http/Controllers/Api/ValeController.php`, `app/Http/Requests/Vale/`, `app/Http/Resources/ValeResource.php` |
| Notificaciones | `app/Http/Controllers/Api/NotificacionController.php`, `app/Http/Resources/NotificacionResource.php` |
| Dispositivos FCM | `app/Http/Controllers/Api/DispositivoFcmController.php`, `app/Services/Notificacion/GestionarDispositivoFcmAction.php` |
| Perfil | `app/Http/Controllers/Api/PerfilUsuarioController.php`, `app/Http/Requests/Perfil/`, `app/Services/Perfil/` |
| Quién entra a qué | `routes/api.php` y `routes/api/*.php` |
| Filtro por dueño | `app/Support/PropietarioActual.php` |
| Filtro por distribuidora | `app/Support/Tenant.php`, `app/Models/Scopes/TenantScope.php` |
