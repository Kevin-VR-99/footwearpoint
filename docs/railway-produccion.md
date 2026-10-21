# Railway: cambios para producción (aplicar DESPUÉS de hacer merge de `feature/produccion-lista`)

Proyecto **Footwear-Point**, entorno **production**. Aquí solo hay nombres de variables, nunca valores.
Servicios: `footwearpoint` (web), `footwearpoint-worker` (cola), `footwearpoint-avisos-vales`,
`footwearpoint-vencer-recoleccion`, `footwearpoint-respaldo` (crons) y `MySQL`.

Sigue el orden: las tablas `sessions` y `cache` tienen que existir **antes** de cambiar los drivers.

## 1. Web (`footwearpoint`): migraciones antes de cada deploy
- Settings → Deploy → **Pre-deploy command**: `php artisan migrate --force`
- Deploy. Confirma en los logs del pre-deploy que corrieron las migraciones `2026_10_21_*`
  (sessions, cache, cache_locks, columnas de Mercado Pago, `procesamiento_iniciado_at`).

## 2. Worker (`footwearpoint-worker`): sin migraciones
- Cambia `RAILPACK_START_CMD` (o el Custom Start Command) a:
  `php artisan queue:work --tries=3 --backoff=60 --timeout=1800 --sleep=3`
- `ProcesarCatalogoConIa` declara `$tries = 1` en el job, así que la IA **no** se reintenta (cada intento cuesta).
  Los correos sí se reintentan hasta 3 veces.

## 3. Web: servidor de producción (FrankenPHP de Railpack) en lugar de `php artisan serve`
Railpack detecta Laravel (`artisan`) y, **si no hay start command**, arranca con `/start-container.sh`:
corre las migraciones (`RAILPACK_SKIP_MIGRATIONS`), `storage:link`, `optimize` (config, route, view y event cache) y
levanta **FrankenPHP/Caddy** con docroot `/app/public`. Ver https://railpack.com/languages/php
- **Borra** el Custom Start Command actual (`php artisan config:clear && php artisan serve ...`).
- Agrega la variable `RAILPACK_SKIP_MIGRATIONS=true`, porque ya migra el pre-deploy del paso 1.
- Si prefieres un comando explícito, el equivalente es:
  `php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache && /start-container.sh`
- Después del deploy, verifica que se puede **subir un catálogo PDF de ~30 MB** (las subidas de Livewire pasan por PHP).
  Si falla por tamaño, agrega a la raíz del repo un `php.ini` con `upload_max_filesize=50M`,
  `post_max_size=55M` y `memory_limit=512M` (va en un PR aparte).
- Verifica también que `/up` responda, que funcionen el login y los subdominios de distribuidora,
  y que el callback de Mercado Pago (`/mercado-pago/callback`) siga funcionando.

## 4. Healthcheck
- Web → Settings → Deploy → **Healthcheck path**: `/up`

## 5. Sesiones y caché en base de datos (solo después del paso 1)
En los 5 servicios de Laravel (`footwearpoint`, `footwearpoint-worker`, `footwearpoint-avisos-vales`,
`footwearpoint-vencer-recoleccion` y `footwearpoint-respaldo` si usa caché):
- `SESSION_DRIVER=database`. El worker no la tiene; agrégala para que todos sean iguales.
- `CACHE_STORE=database`
Al cambiarlo, la gente tendrá que volver a iniciar sesión **una vez**; después ya no se pierden sesiones al desplegar.
Lo ideal es usar *Shared Variables* del entorno para no repetirlas en cada servicio.

## 6. Variables que faltan, por servicio (solo nombres)
- **footwearpoint-worker**: `FIREBASE_CREDENTIALS`, `MP_ACCESS_TOKEN`, `MP_CLIENT_ID`, `MP_CLIENT_SECRET`, `MP_MODO`,
  `MP_PKCE`, `MP_REDIRECT_URI`, `MP_WEBHOOK_SECRET`, `FOOTWEARPOINT_DOMINIO`, `FOOTWEARPOINT_HOSTS_EXTRA`,
  `FOOTWEARPOINT_CONTACTO_EMAIL`, `AUTH_VERIFICACION_OBLIGATORIA`, `SESSION_DRIVER`.
- **footwearpoint-avisos-vales** y **footwearpoint-vencer-recoleccion**: `FOOTWEARPOINT_DOMINIO`,
  `FOOTWEARPOINT_HOSTS_EXTRA`, `FOOTWEARPOINT_CONTACTO_EMAIL` (para los links de los correos).
- **Nuevo cron de Mercado Pago** (paso 7): `APP_*`, `DB_*`, `MAIL_*`, `MP_CLIENT_ID`, `MP_CLIENT_SECRET`, `MP_MODO`,
  `MP_REDIRECT_URI`, `FOOTWEARPOINT_DOMINIO`, `CACHE_STORE`, `LOG_CHANNEL`, `LOG_STACK`.
- Opcionales, solo para la IA en el worker y en la web: `IA_MAX_PAGINAS` (150), `IA_MAX_IMPORTACIONES_DIA` (10),
  `IA_MAX_USD_MES` (150) e `IA_MODELO`. Si no se ponen, se usa el valor por omisión.

## 7. Nuevo cron: renovar tokens de Mercado Pago
- Duplica `footwearpoint-vencer-recoleccion` como **`footwearpoint-renovar-tokens-mp`**.
- Start command: `php artisan mercado-pago:renovar-tokens`
- Cron schedule: `0 15 * * 1` (lunes 15:00 UTC = 9:00 de CDMX). Restart policy: Never.
- Primera vez: córrelo a mano con `--dry-run` para ver cuántas conexiones vencen pronto.
  Las conexiones hechas antes de este cambio no tienen refresh token: 30 días antes del vencimiento estimado
  (conexión + 180 días) se avisa a sus administradores, en la campana y por correo, que vuelvan a conectar.
  El aviso se repite como máximo una vez por semana.
  - Norma (cuenta de MP 489976339), conectada el 20-oct-2026: si reconecta su cuenta **después** del deploy,
    ya queda con refresh token y se renueva sola.

## 8. Limpieza de variables de la web
- Quitar `SEED_PASSWORD` y `SEED_IMAGENES` de `footwearpoint`. En producción nunca se siembra.

## 9. Verificación final
- `railway logs` de cada servicio sin errores; `SELECT COUNT(*) FROM sessions;` crece con los logins.
- Las cabeceras (`curl -I https://footwearpoint.app`) traen `X-Frame-Options`, `X-Content-Type-Options`,
  `Referrer-Policy`, `Permissions-Policy` y `Strict-Transport-Security: max-age=31536000; includeSubDomains`,
  y **no** traen `X-Powered-By`.

---

# Rama `feature/seguridad-rendimiento` (auditoría 21-oct-2026): aplicar DESPUÉS del merge

Solo nombres de variables, nunca valores.

## A. Migración nueva (la corre el pre-deploy `php artisan migrate --force`)
- `2026_10_21_110000_agrega_variantes_a_producto_imagenes`: agrega `url_miniatura` y `url_mediana` (nullable)
  a `producto_imagenes`. No toca datos; las fotos viejas siguen usando `url`.

## B. Mercado Pago: firma obligatoria cuando hay `MP_WEBHOOK_SECRET`
- **Recomendado: configura `MP_WEBHOOK_SECRET` en `footwearpoint` (web).** Con ella, la firma del aviso es
  obligatoria (sin firma, inválida o con `ts` de más de 5 minutos → 401). Sin ella, los avisos se siguen
  aceptando como antes (el pago se verifica en la API de Mercado Pago) y el log avisa
  `falta MP_WEBHOOK_SECRET`; los cobros no se rompen, pero conviene configurarla.
- En ambos casos se descartan avisos repetidos por `data.id`.
- Si configuras la variable, el panel de MP debe tener la misma clave secreta del webhook.
- Verificación: en el panel de MP → Webhooks → "Simular notificación" debe responder 200.

## C. Caché de `/build/*` en Caddy (FrankenPHP de Railpack)
Railpack no usa `public/.htaccess`. En el servicio web agrega la variable:
- `CADDY_SERVER_EXTRA_DIRECTIVES` = `header /build/* Cache-Control "public, max-age=31536000, immutable"`
Verificación: `curl -I https://footwearpoint.app/build/assets/app-XXXX.css` trae ese `Cache-Control`.
(Probado con Caddy 2.11: la directiva entra en la ruta del sitio y solo afecta `/build/*`.)

## D. CSP (Content-Security-Policy-Report-Only)
- Por omisión la web manda `Content-Security-Policy-Report-Only` con nonce; **no bloquea nada**.
  Lo que se bloquearía queda en el log como `CSP: el navegador reportó…` (POST `/api/csp-report`).
- Variable opcional `CSP_MODO`: `report-only` (omisión), `enforce` (obligatoria) u `off`.
  Pasar a `enforce` solo cuando el log lleve unos días limpio.

## E. Nuevo cron: limpiar tokens vencidos de la app
- Duplica `footwearpoint-vencer-recoleccion` como **`footwearpoint-limpiar-tokens`**.
- Start command: `php artisan sanctum:prune-expired --hours=24`
- Cron schedule: `30 10 * * *` (10:30 UTC = 4:30 de CDMX). Restart policy: Never.
- Variables: las mismas `APP_*` y `DB_*` del cron que duplicas.
- No está en `routes/console.php`: en Railway cada comando es su propio servicio cron.

## F. `SESSION_ENCRYPT` (solo documentado, no se cambió)
- `config/session.php` deja `SESSION_ENCRYPT=false`. Con `SESSION_DRIVER=database` el contenido de la sesión
  vive en MySQL; hoy solo guarda ids, el token CSRF y datos de Mercado Pago (PKCE) de corta vida.
- Si se activa (`SESSION_ENCRYPT=true` en todos los servicios a la vez), todas las sesiones abiertas se
  invalidan una vez (la gente vuelve a iniciar sesión). Usa `APP_KEY`, que **no** debe cambiar.
