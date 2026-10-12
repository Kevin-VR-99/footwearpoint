# Aviso de vale por vencer (TG-242 / A13 / E16-02)

Cuando a alguien le queda saldo en un vale y la fecha de vencimiento se acerca, el sistema le avisa
en la app. Es un aviso **automático y diario**: nadie lo manda a mano.

```
php artisan vales:avisar-por-vencer
```

---

## Qué hace, exactamente

Busca los vales que cumplen **todas** estas condiciones:

| Condición | Por qué |
|---|---|
| Estado `activo` | Un vale bloqueado o agotado no se puede usar |
| Saldo mayor que cero | Sin saldo no hay nada que apurarse en usar |
| Todavía **no** vencido | Un aviso que llega tarde solo confunde |
| Vence dentro del margen | Es el aviso propiamente dicho |
| Sin aviso previo | `vales.aviso_vencimiento_enviado_at` está vacío |

A cada uno le deja un aviso en la bandeja de la app y, si la persona tiene el celular registrado,
le manda además una notificación. Después marca `aviso_vencimiento_enviado_at`, así que **aunque el
comando corra dos veces el mismo día, nadie recibe el aviso repetido**.

A quien **no tiene cuenta en la app** no se le avisa y **no se le marca nada**: tener cuenta es
opcional, a esas personas las llama la distribuidora, y si le dan cuenta antes de que el vale venza
el aviso le llegará igual.

El comando mira **todas las distribuidoras** (no corre dentro de ninguna), pero cada aviso se guarda
con la distribuidora de su vale, así que nadie ve nada de otra.

---

## Y la distribuidora (TG-122)

El mismo comando, después de avisar a cada cliente, deja **un resumen diario** en la campana del
panel de **todo el personal activo** de cada distribuidora que tenga vales por vencer:

> **3 vales vencen en los próximos 7 días**
> 2 clientes sin app (llámalos): V-00012 Ana Ruiz, V-00015 Luis Paz.

- Cuenta **todos** los vales por vencer de esa distribuidora (activos, con saldo, sin vencer y
  dentro del margen), aunque al cliente ya se le haya avisado otro día: es el panorama de hoy.
- Nombra (folio y nombre) a los clientes **sin cuenta en la app**, que son los que no reciben el
  aviso y hay que llamar. Nombra hasta 10; del resto dice «y N más». Si todos tienen la app, lo dice.
- **Solo si hay al menos un vale por vencer.**
- **Una vez al día por distribuidora:** si hoy ya hay un resumen suyo (tipo
  `vales_por_vencer_resumen`), no se repite aunque el comando corra dos veces. No hizo falta
  columna nueva.

Además, con el **mismo margen**:

- **Panel → Vales:** insignia «Por vencer» junto a la fecha y un filtro «Por vencer» (del más
  urgente al menos).
- **App → Mis vales:** «Vence pronto» resaltado. La API manda `por_vencer` en cada vale (ver
  `contrato-api.md`), así el margen se cambia en un solo lugar.

La salida del comando agrega una línea:

```
Resumen al personal: 1 distribuidoras, 2 avisos.
```

---

## El margen de días

```
VALES_DIAS_AVISO_VENCIMIENTO=7
```

Siete días, con vales que duran noventa por omisión: alcanza para ir a la tienda sin que el aviso
llegue tan pronto que se olvide. El backlog lo dejó "a definir", así que el equipo puede moverlo
cambiando esa variable, sin tocar código ni desplegar nada más.

Si algún día cada distribuidora quiere el suyo, hace falta una columna en
`configuraciones_distribuidora` (migración).

---

## Cómo programarlo en Railway

Igual que el respaldo diario (ver `respaldos.md`): un **servicio cron** aparte, con el mismo
repositorio y las mismas variables que la app.

1. En el proyecto de Railway: **New** → **Empty Service**, nombre `footwearpoint-avisos`.
2. Conectar el mismo repositorio.
3. **Variables**: las mismas de la app (referencias), para que llegue a la base de datos y a
   Firebase.
4. **Settings** → **Cron Schedule**: `0 14 * * *` (todos los días a las 8 de la mañana, hora de
   México; Railway usa UTC).
5. **Deploy / Start Command**: `php artisan vales:avisar-por-vencer`

La hora conviene que sea de mañana: un aviso a medianoche se pierde entre lo demás.

---

## Probarlo sin esperar

El margen se puede forzar en la llamada, para no tener que esperar a que un vale se acerque de
verdad a su fecha:

```
php artisan vales:avisar-por-vencer --dias=60
```

Responde cuántos revisó, a cuántos avisó y cuántos no tenían cuenta en la app:

```
Vales por vencer revisados: 3. Avisados: 2. Sin cuenta en la app: 1.
```

---

## Si algo falla

Un vale que falla **no detiene a los demás**: queda en el log (`No se pudo avisar que un vale está
por vencer`) y, como no se marcó, **al día siguiente se vuelve a intentar**.

Si Firebase falla, el aviso de la bandeja ya quedó guardado y la persona lo ve al entrar a la app;
solo se pierde la notificación en la pantalla del celular.
