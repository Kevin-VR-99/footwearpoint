# Respaldos de la base de datos (TG-235 / G17)

FootwearPoint tiene **dos capas** de respaldo de la base MySQL de Railway:

1. **Respaldos nativos de Railway** del volumen de MySQL (rápidos de restaurar, pero viven dentro de Railway).
2. **Respaldo diario fuera de Railway**: `php artisan respaldo:base-de-datos` vuelca la base con `mysqldump`, la comprime y la guarda en un **bucket privado de Cloudflare R2**.

Nunca pongas contraseñas, tokens ni llaves en este documento ni en el código.

---

## 1. Respaldos nativos de Railway

En Railway: proyecto **meticulous-cooperation** → servicio **MySQL** → pestaña **Backups**.

- Activar el programa **Daily** (se guarda 6 días) y **Weekly** (se guarda 27 días). Opcional: **Monthly** (89 días).
- Opcional: **Point-in-time recovery** (binlog), para volver a cualquier momento de los últimos 7 días.
- Se cobran como almacenamiento de volumen, solo por lo incremental.
- Limitaciones: solo se restauran en el mismo proyecto y entorno, y si se borra el volumen se borran sus respaldos. Por eso existe la capa 2.

**Restaurar:** en la misma pestaña Backups, elegir el respaldo → **Restore**. Railway prepara el cambio y hay que aplicarlo (Deploy) en el servicio MySQL. La aplicación se reconecta sola; conviene avisar antes porque se pierde lo escrito después de ese respaldo.

---

## 2. Respaldo diario a R2 (`respaldo:base-de-datos`)

### Qué hace

1. `mysqldump --single-transaction --quick --routines --triggers --no-tablespaces` (no bloquea tablas). La contraseña va en un archivo de opciones temporal, nunca en la línea de comandos ni en el log.
2. Revisa que el volcado termine con `-- Dump completed`; si no, **no** lo guarda y falla.
3. Lo comprime (gzip) y lo sube a `respaldos/AAAA/MM/footwearpoint-AAAAMMDD-HHMMSS.sql.gz` (hora UTC) y revisa que el tamaño guardado coincida.
4. Borra los respaldos `footwearpoint-*.sql.gz` con más de `RESPALDO_RETENCION_DIAS` días (30 por omisión; 0 = no borra ninguno). Otros archivos del bucket no se tocan.
5. Si algo falla termina con código 1, y Railway marca la ejecución como fallida (y avisa por correo según la configuración de la cuenta).

### Bucket privado en Cloudflare R2 (una sola vez)

1. En Cloudflare → R2 → **Create bucket**, por ejemplo `footwearpoint-respaldos`. **Sin** acceso público (sin r2.dev ni dominio propio). No usar el bucket de las fotos, que es público.
2. R2 → **Manage API tokens** → **Create API token** con permiso **Object Read & Write** limitado a **ese bucket**.
3. Anotar el Access Key ID, el Secret Access Key y el endpoint `https://<ACCOUNT_ID>.r2.cloudflarestorage.com`.
4. Opcional: una regla de ciclo de vida en el bucket como red de seguridad (por ejemplo, borrar objetos de más de 45 días).

### Variables

| Variable | Valor |
|---|---|
| `RESPALDO_AWS_ACCESS_KEY_ID` | Access Key ID del token de R2 |
| `RESPALDO_AWS_SECRET_ACCESS_KEY` | Secret Access Key del token de R2 |
| `RESPALDO_AWS_BUCKET` | `footwearpoint-respaldos` |
| `RESPALDO_AWS_ENDPOINT` | `https://<ACCOUNT_ID>.r2.cloudflarestorage.com` |
| `RESPALDO_AWS_DEFAULT_REGION` | `auto` |
| `RESPALDO_AWS_USE_PATH_STYLE_ENDPOINT` | `true` |
| `RESPALDO_RETENCION_DIAS` | `30` |
| `RESPALDO_MYSQLDUMP_OPCIONES` | vacío; con el cliente de Oracle (MySQL 8) y GTID activo: `--set-gtid-purged=OFF` |

Además, el servicio necesita las mismas variables de la aplicación para arrancar Laravel y conectarse a la base: `APP_KEY`, `APP_ENV`, `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`. En Railway conviene usar **referencias** a las del servicio `footwearpoint` o `MySQL` (por ejemplo `${{MySQL.MYSQLHOST}}`), no copiar valores.

### Servicio cron en Railway

1. En el proyecto: **New** → **GitHub Repo** → `Kevin-VR-99/footwearpoint` (rama `main`). Nombre sugerido: `footwearpoint-respaldo`.
2. **Settings → Deploy → Custom Start Command:** `php artisan respaldo:base-de-datos`
3. **Settings → Cron Schedule:** `0 9 * * *` (09:00 UTC = 03:00 en Ciudad de México). Railway corre el comando a esa hora y el contenedor termina al acabar; si una ejecución sigue corriendo, la siguiente se salta.
4. **Cliente de MySQL en la imagen** (solo en este servicio): agregar la variable de build del constructor que instala paquetes del sistema, por ejemplo `RAILPACK_DEPLOY_APT_PACKAGES=default-mysql-client` (Railpack) o `NIXPACKS_PKGS=mysql80` (Nixpacks), según el constructor que use el servicio. Comprobar con `railway ssh --service footwearpoint-respaldo -- mysqldump --version` o en el log de la primera ejecución.
5. Sin dominio público ni puerto: no atiende peticiones.
6. Variables: las de la tabla anterior más las de la aplicación (ver arriba).
7. Probar una vez a mano (**Deploy** del servicio) y revisar el log: debe decir `Respaldo guardado: respaldos/AAAA/MM/footwearpoint-....sql.gz`.

### Restaurar un respaldo de R2

Hazlo primero en una base **nueva** y revisa antes de tocar producción.

1. Descargar el archivo del bucket (panel de Cloudflare → R2 → bucket → objeto → Download, o con cualquier cliente S3 usando el token).
2. Descomprimir: `gunzip footwearpoint-AAAAMMDD-HHMMSS.sql.gz`
3. Crear una base vacía, por ejemplo `footwearpoint_restaurada` (`CREATE DATABASE footwearpoint_restaurada CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`).
4. Cargarlo: `mysql -h <host> -P <puerto> -u <usuario> -p footwearpoint_restaurada < footwearpoint-AAAAMMDD-HHMMSS.sql` (para Railway desde fuera, usar el TCP Proxy público del servicio MySQL).
5. Revisar: `SELECT COUNT(*) FROM distribuidoras;`, pedidos, pagos y suscripciones; comparar con lo esperado.
6. Para usarla: cambiar `DB_DATABASE` del servicio `footwearpoint` a la base restaurada (o restaurar sobre la original en una ventana de mantenimiento) y redeploy. **No** cambiar `APP_KEY`: los tokens de Mercado Pago guardados están cifrados con ella.

### Simulacro

Al menos una vez por entrega, restaurar el respaldo más reciente en una base nueva (pasos anteriores) y anotar fecha, archivo, tiempo y conteos. Es la evidencia del criterio "Existen respaldos periódicos programados" (E17-04).

---

## 3. Probar el comando en local

Con un `.env` que apunte a un bucket de prueba y `mysqldump` en el PATH:

`php artisan respaldo:base-de-datos`

Las pruebas automatizadas (`tests/Feature/Respaldo/RespaldoBaseDeDatosTest.php`) no ejecutan `mysqldump` ni suben nada.
