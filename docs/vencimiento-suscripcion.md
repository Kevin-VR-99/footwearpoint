# Vencimiento de la mensualidad

Cuando pasa `fecha_fin` (el último día pagado), la suscripción queda **vencida**
y la distribuidora sigue operando **5 días** de gracia. Al **sexto** día sin pago
se puede suspender por falta de pago. Las fechas son de calendario en
`America/Mexico_City`.

```
php artisan suscripciones:vencer
```

## Qué hace

| Día | Qué pasa |
|---|---|
| 3 y 1 días antes | Correo de que la mensualidad está por vencer |
| Día del vencimiento | Sigue activa. Correo y aviso en el panel |
| Días 1 a 5 | La suscripción pasa a `vencida`. Sigue operando. Aviso en el panel y un correo por día |
| Día 6 y los que sigan, modo avisos | Sigue vencida, **sin cortar el acceso**, y un correo por día |
| Día 6, modo suspender | La distribuidora queda `suspendida` con motivo `falta_pago` y recibe el correo. El personal entra solo a la pantalla de pago |

Correrlo otra vez el mismo día no manda de nuevo el mismo aviso ni vuelve a
suspender. Si el comando no corrió un día, ese aviso no se recupera después.
Si el correo falla, ese día no se reintenta.

Una suspensión **manual** (la del admin general) guarda motivo `manual`. El
comando no la cambia y un pago no la levanta. Una distribuidora que ya estaba
suspendida sin motivo se trata igual que una manual.

## Al pagar

- Vigente, o dentro de la gracia: la nueva `fecha_fin` es la anterior más un mes.
- Después del día 5, o ya suspendida por falta de pago: la nueva `fecha_fin` es el día en que se aplica el pago más un mes.
- Si el mes siguiente es más corto, queda el último día de ese mes (del 31 de enero al 28 de febrero, o 29 si el año es bisiesto).
- Si estaba suspendida por falta de pago, al aplicarse el pago vuelve a `activa`.

El pago se hace en el panel web. La app no cobra la mensualidad: con la
distribuidora suspendida, la app sigue sin abrir.

## Cómo activar la suspensión

Hoy el comando, sin opciones, trabaja en **solo avisos**. No corta el acceso.

Para suspender al sexto día, una de estas dos, y solo cuando los términos que
describen la gracia y la suspensión ya estén publicados:

- el comando con `--suspender`
- la variable `SUSCRIPCION_VENCIMIENTO=suspender`

Simulación, sin escribir ni mandar correos:

```
php artisan suscripciones:vencer --dry-run
php artisan suscripciones:vencer --suspender --dry-run
```

## Cron

No está creado. Cuando se decida activarlo, es un servicio cron aparte, igual
que el de recolección vencida, con el mismo repositorio y las mismas variables
que la app.

1. En Railway: **New** → **Empty Service**, nombre `footwearpoint-vencer-suscripcion`.
2. Conectar el mismo repositorio.
3. Variables: las mismas de la app. Dejar `SUSCRIPCION_VENCIMIENTO` sin definir
   mientras solo se quiera avisar. Ponerla en `suspender` solo al activar el corte.
4. **Settings** → **Cron Schedule**: `20 14 * * *` (8:20 de la Ciudad de México;
   Railway usa UTC).
5. **Start Command**: `php artisan suscripciones:vencer`
   (o `php artisan suscripciones:vencer --suspender` si el corte va en el comando
   y no en la variable).
6. Restart policy: Never.

El servicio no se crea con este cambio.
