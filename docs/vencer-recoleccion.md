# Vencer pedidos no recogidos (TG-125 / E13-02)

Cuando un pedido queda **listo para entrega**, el cliente tiene un plazo para recogerlo: los
**días máximos de recolección** de la distribuidora (Configuración general; 5 por omisión). Si ese
plazo pasa y nadie lo recoge, el sistema lo marca solo como **Recolección vencida**
(`vencido_recoleccion`). Es un proceso **automático y diario**:

```
php artisan pedidos:vencer-recoleccion
```

---

## Qué hace, exactamente

Busca los pedidos que cumplen **todas** estas condiciones:

| Condición | Por qué |
|---|---|
| Estado `listo_entrega` | Solo un pedido listo puede vencer; uno entregado ya se resolvió |
| Tiene `fecha_limite_recoleccion` | Se pone al marcarlo listo (TG-51) |
| Esa fecha **ya pasó** | Es el vencimiento propiamente dicho |

A cada uno lo cambia a `vencido_recoleccion` con el mismo servicio que usa el panel
(`CambiarEstadoPedidoService`), así que queda igual que un cambio hecho a mano:

- **Historial** del pedido, con el comentario «Venció el plazo de recolección» y sin empleado (lo hizo el sistema).
- **Auditoría** (`pedido.cambio_estado`) con la distribuidora del pedido.
- **Aviso al personal** activo de la distribuidora, en la campana del panel: «Pedido PED-…: venció el plazo de recolección».

**Se puede correr las veces que sea:** un pedido vencido deja de estar `listo_entrega` y no se
vuelve a tocar. Antes de cambiarlo se vuelve a leer con candado, por si alguien lo entregó
justo en ese momento.

El comando mira **todas las distribuidoras**, pero cada pedido se procesa dentro de la suya.

Qué hacer después con el pedido (sustituir o reembolsar el anticipo) lo decide la distribuidora;
este proceso no lo toca.

---

## Lo que ve cada quien

- **Cliente (app):** al quedar listo le llega el aviso «Ya puedes pasar a recoger… Recógelo antes
  del dd/mm/aaaa.», y en el detalle del pedido ve «Recoge antes del dd/mm/aaaa». Si vence, el
  estado dice «Recolección vencida».
- **Panel:** en la lista de pedidos, una insignia «Por vencer» (48 horas o menos) o «Recolección
  vencida» junto a los pedidos listos; en el detalle, la alerta de plazo (TG-244).
- **API:** `fecha_limite_recoleccion` en el pedido (ver `contrato-api.md`).

---

## Cómo programarlo en Railway

Igual que el aviso de vales (`aviso-vale-por-vencer.md`): un **servicio cron** aparte, con el mismo
repositorio y las mismas variables que la app.

1. En el proyecto de Railway: **New** → **Empty Service**, nombre `footwearpoint-vencer-recoleccion`.
2. Conectar el mismo repositorio.
3. **Variables**: las mismas de la app (referencias), para que llegue a la base de datos.
4. **Settings** → **Cron Schedule**: `10 14 * * *` (todos los días a las 8:10 de la mañana, hora de
   México; Railway usa UTC). Diez minutos después del aviso de vales, para no correr a la vez.
5. **Deploy / Start Command**: `php artisan pedidos:vencer-recoleccion`

De mañana, para que el personal vea los avisos al empezar el día.

---

## Probarlo sin cambiar nada

```
php artisan pedidos:vencer-recoleccion --dry-run
```

Dice cuántos pedidos vencería y sus folios, sin tocarlos:

```
Simulación: 2 pedidos vencerían.
  PED-20261008-0002
  PED-20261009-0005
```

Sin `--dry-run` responde cuántos encontró y cuántos marcó:

```
Pedidos con plazo vencido: 2. Marcados como vencidos: 2.
  PED-20261008-0002
  PED-20261009-0005
```

Las pruebas están en `tests/Feature/Pedido/VencerRecoleccionTest.php`.
