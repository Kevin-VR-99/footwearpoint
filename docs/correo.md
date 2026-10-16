# Correo saliente

Cómo manda correo el sistema, y la trampa que ya nos costó dos días una vez.

## Cómo está montado

Todo el correo sale por **SMTP de Gmail**, desde la cuenta del equipo
`mvpla.footwearpoint@gmail.com`, por el puerto **587**.

Las variables viven en Railway y están en **tres servicios**, porque los tres
mandan correo:

| Servicio | Qué manda |
|---|---|
| `footwearpoint` | Confirmación de correo, restablecer contraseña, aviso de alta |
| `footwearpoint-avisos-vales` | Aviso de vale por vencer (TG-242) |
| `footwearpoint-vencer-recoleccion` | Aviso de recolección vencida |

Si se cambia una variable de correo, hay que cambiarla **en los tres**.

| Variable | Valor |
|---|---|
| `MAIL_MAILER` | `smtp` |
| `MAIL_HOST` | `smtp.gmail.com` |
| `MAIL_PORT` | `587` |
| `MAIL_USERNAME` | `mvpla.footwearpoint@gmail.com` (la dirección completa) |
| `MAIL_PASSWORD` | Una **contraseña de aplicación** de Google, 16 caracteres, sin espacios |
| `MAIL_TIMEOUT` | `10` |

**`MAIL_TIMEOUT` no es opcional.** Sin ella, PHP usa `default_socket_timeout`,
que son 60 segundos: si la conexión se cuelga, la petición se queda esperando
un minuto y el servidor la corta **antes** de que Laravel alcance a escribir
en el log por qué falló. Con 10 segundos el fallo sí queda escrito.

## La trampa: las contraseñas de aplicación se revocan solas

Gmail no acepta la contraseña normal de la cuenta desde 2022: hace falta una
**contraseña de aplicación**, que se genera aparte. Y Google las borra
**todas, de golpe y sin avisar** cuando pasa cualquiera de estas dos cosas:

- se **cambia la contraseña** de la cuenta de Google;
- se **desactiva la verificación en dos pasos**.

Pasó en octubre de 2026: alguien cambió la contraseña de la cuenta de Google y
el correo dejó de salir en todo el sistema. Nadie lo relacionó, porque la
cuenta de Gmail se seguía abriendo con normalidad.

**Antes de tocar la contraseña de `mvpla.footwearpoint@gmail.com`, avisa al
equipo.** Si ya se cambió, hay que generar una contraseña de aplicación nueva
y reemplazar `MAIL_PASSWORD` en los tres servicios.

### Cómo se ve cuando pasa

En el panel, al afiliar a alguien con acceso a la app o al tocar «Enviar
enlace» o «Reenviar confirmación», sale el aviso en ámbar de que el correo no
se pudo mandar. Y en los logs de Railway:

```
No se pudo enviar el correo de confirmacion
  excepcion: Symfony\Component\Mailer\Exception\TransportException
  mensaje:   535-5.7.8 Username and Password not accepted
```

El `535` es siempre esto: credenciales rechazadas.

Ojo con la diferencia, porque despista: un **550** o un **535** son el
servidor de Gmail **rechazando** algo, y salen rápido. Una conexión que nunca
se abre sale como la misma `TransportException` pero con
`Connection could not be established` y tarda lo que diga `MAIL_TIMEOUT`.

### Cómo se arregla

1. Entrar a la cuenta de Google del equipo → Seguridad → Verificación en dos
   pasos → Contraseñas de aplicaciones. Generar una nueva.
2. Ponerla en `MAIL_PASSWORD` en los **tres** servicios de Railway, sin
   espacios.
3. Desplegar los tres.
4. Comprobarlo (abajo).
5. Reenviar la confirmación, desde el panel, a quien se haya quedado sin ella
   mientras tanto. Se ven con:

   ```bash
   php artisan tinker --execute="echo App\Models\Usuario::whereNull('email_verified_at')->count();"
   ```

   Importa porque, con A11 activo, quien no tiene el correo confirmado **no
   puede enviar pedidos ni pagar** desde la app. Al personal del panel no le
   afecta: el middleware `correo.verificado` solo está en rutas de la API.

## Cómo comprobar que de verdad funciona

Que no salga error **no** basta: el correo puede salir y acabar en spam. La
única prueba que cierra el tema es que **una dirección que no sea del equipo
reciba uno**. Afiliar un cliente mayorista de prueba con un correo de fuera
(Outlook, Yahoo, el de la escuela) y confirmar que llega.

## Para más adelante

Gmail no es un servicio de correo transaccional. Dos límites que nos van a
alcanzar si esto crece:

- unos **500 envíos al día**, y la cuenta se bloquea un rato al pasarse;
- **entrega irregular**: los correos caen en spam con facilidad, porque se
  manda desde `@gmail.com` y no desde un dominio propio autenticado.

Cuando estorbe, la salida es un proveedor por API (Resend, Postmark, Brevo)
con el dominio `footwearpoint.app` verificado. Además va por HTTPS, así que
se acaban también los problemas de puertos SMTP bloqueados.
