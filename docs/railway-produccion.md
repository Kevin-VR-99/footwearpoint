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

## B. Mercado Pago: firma obligatoria y webhook fail-closed (oct-2026)
- **`MP_WEBHOOK_SECRET` es obligatoria en `footwearpoint` (web) en producción y staging.** Sin ella el
  webhook responde **503** a todo aviso (Mercado Pago lo reintenta) y el log marca
  `falta MP_WEBHOOK_SECRET; el aviso se rechaza (fail-closed)`: los pagos por webhook NO se aplican
  hasta configurarla (la página de regreso de Mercado Pago sigue aplicando el pago).
- Con la variable, la firma es obligatoria: sin firma, inválida o con `ts` de más de 5 minutos → 401
  (HMAC-SHA256 de `id:…;request-id:…;ts:…;`, comparación con `hash_equals`).
- Solo en `local`/`testing`, `MP_WEBHOOK_PERMITIR_SIN_CLAVE` (true por omisión) deja procesar avisos sin
  clave consultando la API; en producción/staging se ignora.
- Se descartan avisos repetidos por `data.id`. El panel de MP debe tener la misma clave secreta.
- Verificación: en el panel de MP → Webhooks → "Simular notificación" debe responder 200.

## C. Caché de `/build/*` en Caddy (FrankenPHP de Railpack)
Railpack no usa `public/.htaccess`. En el servicio web agrega la variable:
- `CADDY_SERVER_EXTRA_DIRECTIVES` = `header /build/* Cache-Control "public, max-age=31536000, immutable"`
Verificación: `curl -I https://footwearpoint.app/build/assets/app-XXXX.css` trae ese `Cache-Control`.
(Probado con Caddy 2.11: la directiva entra en la ruta del sitio y solo afecta `/build/*`.)

## D. CSP obligatoria (oct-2026)
- Por omisión la web manda `Content-Security-Policy` (**bloquea**) con nonce por petición para Vite,
  Livewire y los `<script>` propios. Las violaciones siguen en el log como `CSP: el navegador reportó…`
  (POST `/api/csp-report`).
- Variable opcional `CSP_MODO`: `bloquear` (omisión; alias `enforce`), `reportar` (alias `report-only`)
  u `off`. Si algo se rompe en producción, `CSP_MODO=reportar` regresa a solo reporte sin desplegar código.
- Riesgo residual documentado: `script-src` conserva `'unsafe-eval'` (Alpine evalúa expresiones con
  `new Function`) y `style-src` conserva `'unsafe-inline'` (atributos `style=""` y estilos de x-show /
  x-transition); `img-src` y `frame-src` admiten `https:` (fotos en R2/CDN y visores de PDF).

## E. Nuevo cron: limpiar tokens vencidos de la app
- Duplica `footwearpoint-vencer-recoleccion` como **`footwearpoint-limpiar-tokens`**.
- Start command: `php artisan sanctum:prune-expired --hours=24`
- Cron schedule: `30 10 * * *` (10:30 UTC = 4:30 de CDMX). Restart policy: Never.
- Variables: las mismas `APP_*` y `DB_*` del cron que duplicas.
- No está en `routes/console.php`: en Railway cada comando es su propio servicio cron.

## F. `SESSION_ENCRYPT` cifrada por omisión y sesión del admin general (oct-2026)
- `config/session.php` usa `SESSION_ENCRYPT=true` si la variable no existe (si existe, se respeta: si en
  Railway está en `false`, bórrala o ponla en `true`). Al desplegar, **las sesiones abiertas se cierran una
  vez** (la gente vuelve a iniciar sesión). Usa `APP_KEY`, que **no** debe cambiar.
- Sesión persistente: el panel de distribuidora sigue igual (cookie remember + `SESSION_LIFETIME` larga, que
  no se bajó). El **admin general** ya no: entra sin cookie remember y su cookie de sesión se borra al cerrar
  el navegador. `SESSION_ADMIN_GENERAL_PERSISTENTE=true` regresa al comportamiento anterior.

## G. Nivel de logs en producción (solo documentado, no se cambió)
- Web y worker: `LOG_LEVEL=warning` y `LOG_CHANNEL=stderr`. Railway guarda la salida estándar
  y así no se llena el disco efímero con `storage/logs/laravel.log` ni se registran consultas de depuración.
- `debug` solo en local. Si hace falta investigar algo en producción, subirlo un rato y regresarlo.
- `CSP_MODO`: `upgrade-insecure-requests` ahora lo pone el middleware `PoliticaSeguridadContenido`
  (dentro de la política con `bloquear`; en `reportar`/`off` va en su propia cabecera
  `Content-Security-Policy`, porque el navegador ignora esa directiva en Report-Only).

## H. Monitoreo de errores (Sentry) y de caída (`/up`)
**Sentry** (opcional; sin DSN no se manda nada):
1. Crear un proyecto *Laravel* en sentry.io y copiar su DSN.
2. En Railway, servicios **web** y **worker** → Variables: `SENTRY_LARAVEL_DSN=<dsn>` y
   `SENTRY_ENVIRONMENT=production`. Opcional: `SENTRY_TRACES_SAMPLE_RATE` (por defecto 0.05 = 5 %).
3. Redeploy y probar con `php artisan sentry:test` desde la shell del servicio.
- `send_default_pii` está fijo en `false` (`config/sentry.php`): no viajan IP, cookies, usuario ni cuerpo.
  Los bindings de SQL tampoco se mandan (`SENTRY_*_SQL_BINDINGS_ENABLED=false` por defecto).

**Monitor externo de `/up`:** el healthcheck de Railway solo reinicia; nadie se entera.
- UptimeRobot, Better Stack o similar: monitor HTTP(S) a `https://footwearpoint.app/up`, cada 5 min,
  esperar 200, alerta por correo/Telegram al equipo. `/up` no requiere sesión ni toca datos.
- Opcional: un segundo monitor de palabra clave sobre `/login` para detectar errores 500 del panel.

## I. Exportaciones grandes en la cola
- Reporte (PDF/Excel) con más de `EXPORTACIONES_UMBRAL_REPORTE` filas estimadas (1500) y listado de pedidos
  con más de `EXPORTACIONES_UMBRAL_LISTADO` filas (2000) se arman en el **worker**; lo demás sigue siendo
  descarga inmediata. Clientes directos y mayoristas siguen siempre inmediatos (ya se cargan completos en pantalla).
- Web y worker: `EXPORTACIONES_DISK=catalogos` (bucket **privado** de R2 que ya existe; los archivos quedan
  en `exportaciones/{distribuidora_id}/`). Sin la variable usa `local`, que en Railway se borra en cada deploy.
- El aviso llega a **Notificaciones** con un enlace firmado de 30 min (`EXPORTACIONES_MINUTOS_ENLACE`); el
  archivo vive 24 h (`EXPORTACIONES_HORAS_VIGENCIA`). Solo lo baja quien lo pidió, en su distribuidora.
- Cron opcional `footwearpoint-limpiar-exportaciones`: start command `php artisan exportaciones:limpiar`,
  schedule `15 11 * * *` (5:15 CDMX), restart policy Never.
- Migración nueva: `2026_10_21_130000_crea_exportaciones` (la corre el pre-deploy).

---

# Regla: `db:seed` nunca se corre en producción

**Nunca** corras `php artisan db:seed` ni `migrate --seed` / `migrate:fresh --seed` en producción.

- Los seeders de demostración (`DemoDistribuidoraSeeder`, `CatalogoMaestroDemoSeeder`, `DemoCatalogoDistribuidorasSeeder`, `DemoContactosSeeder`) usan `firstOrCreate`: un `db:seed` en producción **volvería a crear** el catálogo demo, las distribuidoras y los contactos de prueba que se limpiaron con `pruebas:limpiar-produccion` / `catalogo:borrar-demo`.
- Candado: en `APP_ENV=production` esos seeders se saltan solos (`Database\Seeders\Support\DemoEnProduccion`). Solo corren si se pone a propósito `SEED_DEMO_EN_PRODUCCION=true`. No lo pongas en Railway.
- Lo que sí se puede correr en producción, de forma explícita: `php artisan db:seed --class=LineasMarcasSeeder --force` (catálogo de líneas y marcas; ya se usó así).
- **No borres el seeder de demostración**: unas 1586 pruebas dependen de él (entre ellas `BorrarCatalogoDemoTest` y `Sprint4/CatalogoDemoTest`). Fuera de producción sigue funcionando igual.

## Limpiar producción para pruebas (`pruebas:limpiar-produccion`)

1. Verifica que haya respaldo de menos de 24 h (`respaldo:ejecutar` si no).
2. Simulación: `php artisan pruebas:limpiar-produccion --conservar-importacion=5` y revisa importaciones, vales con saldo y filas por tabla.
3. Aplicar: `php artisan pruebas:limpiar-produccion --conservar-importacion=5 --confirmar` (añade `--incluir-vales-con-saldo` solo si se confirmó que esos vales son de prueba).