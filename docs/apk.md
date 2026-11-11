# APK de la app móvil (TG-183)

Cómo generar el APK que apunta al servidor en producción (Railway) y cómo comprobar que llegan los avisos push.

## Qué necesita

- Flutter estable (probado con 3.47.7) y Android SDK.
- `mobile/android/app/google-services.json` del proyecto de Firebase `footwearpoint`, registrado para el paquete `com.footwearpoint.app`. Ya está en el repositorio: es configuración del cliente, no un secreto.
- El servidor necesita `FIREBASE_CREDENTIALS` (la llave de la cuenta de servicio). Vive solo en Railway; **nunca** se sube al repositorio.

## Dirección del servidor

La app toma la dirección de la API de `--dart-define=API_URL=...` (ver `mobile/lib/config.dart`). Sin ese parámetro usa **producción** (`https://footwearpoint.app/api`). Para probar con Laravel local en el emulador: `--dart-define=API_URL=http://10.0.2.2:8000/api`.

Para producción:

```
https://footwearpoint.app/api
```

También funciona `https://footwearpoint-production.up.railway.app/api` (Railway; el servidor la acepta por `FOOTWEARPOINT_HOSTS_EXTRA`), pero se prefiere el dominio propio.

## Generar el APK

```powershell
cd mobile
flutter pub get
flutter build apk --release --dart-define=API_URL=https://footwearpoint.app/api
```

Queda en `mobile/build/app/outputs/flutter-apk/app-release.apk`.

- **Firma:** ver la sección «Llave de firma» aquí abajo. Con `android/key.properties` el APK sale firmado para publicar; sin él sale firmado con la llave de depuración y el build lo avisa en pantalla.
- **Versión:** sale de `version:` en `mobile/pubspec.yaml` (`nombre+versionCode`). Hay que subir el número después del `+` en cada APK que se reparta, para que Android lo acepte como actualización.

## Llave de firma

Android solo deja **actualizar** una app instalada si la versión nueva viene firmada con la **misma** llave que la anterior. Si la llave o su contraseña se pierden no hay forma de recuperarlas ni de volver a firmar: habría que publicar otra app y pedirle a cada persona que la instale de cero. Por eso la llave y sus contraseñas viven **fuera del repositorio**, con el esquema de custodia de abajo.

### Certificado de la llave del proyecto

La llave se creó el 7 de noviembre de 2026 (TG-324). Estos datos son **públicos**: van dentro de cada APK firmado y cualquiera los puede leer.

| Dato | Valor |
| --- | --- |
| Titular | `CN=FootwearPoint, OU=Equipo FootwearPoint, O=FootwearPoint, L=Ocosingo, ST=Chiapas, C=MX` |
| Alias | `footwearpoint` |
| Almacén | PKCS12 con clave RSA de 2048 bits (el archivo se llama `.jks`, pero es PKCS12) |
| Vigencia | Del 07/11/2026 al 25/03/2054 |
| SHA-256 del certificado | `96:0E:8A:79:C0:23:6B:4F:89:51:C7:EB:E6:18:1C:A4:2E:21:B7:D8:CD:55:99:27:B3:2B:D7:5D:D4:2D:FE:8F` |

`apksigner` muestra la misma huella sin los dos puntos y en minúsculas: `960e8a79c0236b4f8951c7ebe6181ca42e21b7d8cd559927b32bd75dd42dfe8f`.

**La llave ya existe: no se vuelve a crear.** Una llave nueva tendría otra huella, y la app publicada con esta dejaría de poder actualizarse.

### Esquema de custodia

| Qué | Dónde vive |
| --- | --- |
| Original (`footwearpoint.jks`) | `C:\Users\kevin\Documents\llaves-footwearpoint\`, fuera del repositorio |
| Respaldo cifrado (`footwearpoint-llave-respaldo.7z`) | Carpeta de Google Drive con acceso restringido a las personas custodias de la llave, y una copia junto al original |
| Contraseña de la llave | Gestor de contraseñas de cada persona custodia |
| Contraseña del respaldo | Gestor de contraseñas de cada persona custodia; es distinta de la de la llave |
| `key.properties` | Solo en la computadora que firma, en `mobile/android/`; está ignorado por Git |

- **El original vive fuera del repositorio a propósito.** `*.jks` está en `android/.gitignore`, y `git clean -x` borra los archivos ignorados: una llave guardada dentro del repo se puede perder con un solo comando.
- **El respaldo** es un `.7z` cifrado con AES-256, nombres de archivo incluidos (`-mhe=on`), y contiene **solo** el `.jks`. Su contraseña es distinta de la de la llave: quien consiga una todavía necesita la otra.
- **Las contraseñas no se mandan** por WhatsApp, correo, Taiga ni Drive, porque ahí se quedan guardadas en el historial y en los respaldos del celular. Se pasan en persona o con un enlace de un solo uso que caduque, y cada persona custodia las guarda en su gestor.
- **Huellas de los archivos**, para comprobar sin contraseña que una copia está intacta (`Get-FileHash -Algorithm SHA256 <archivo>`):
  - `footwearpoint.jks`: `CCFAEE2F451110E95C52B91F93EFA927EA179671FC6D37C56325DCCEEFB5D10A`
  - `footwearpoint-llave-respaldo.7z`: `B440C03591BE2741A6BB4175740F30809F3CFD3EE96AE80B544C9DF2C00A4DF0` (cambia si se rehace el respaldo; en ese caso, actualizarla aquí)

### Cómo se creó (no repetir)

Como referencia, este es el comando equivalente al que se usó:

```powershell
keytool -genkeypair -v -keystore "C:\Users\kevin\Documents\llaves-footwearpoint\footwearpoint.jks" -storetype PKCS12 -alias footwearpoint -keyalg RSA -keysize 2048 -validity 10000 -dname "CN=FootwearPoint, OU=Equipo FootwearPoint, O=FootwearPoint, L=Ocosingo, ST=Chiapas, C=MX"
```

`keytool` viene con el JDK de Android Studio (`C:/Program Files/Android/Android Studio/jbr/bin`). La contraseña se escribe en la pregunta oculta que hace el propio `keytool`: nunca con `-storepass` en el comando, porque quedaría en el historial de PowerShell. En PKCS12 el almacén y la llave usan la misma contraseña. Los datos de `-dname` quedan dentro del certificado y son públicos: por eso el titular es el proyecto y no una persona.

### Configurarla en la computadora que firma

Copiar `mobile/android/key.properties.example` a `mobile/android/key.properties` y llenarlo:

```properties
storeFile=C:/Users/kevin/Documents/llaves-footwearpoint/footwearpoint.jks
storePassword=...
keyAlias=footwearpoint
keyPassword=...
```

- `storeFile` es la ruta **absoluta** al `.jks`, escrita **con diagonales `/`**. En un `.properties` la `\` es carácter de escape: con `C:\Users\...` Gradle no encontraría el archivo.
- `storePassword` y `keyPassword` llevan la misma contraseña.
- No dejar espacios al final de la línea: cuentan como parte del valor.
- Si firma otra computadora, se restaura el `.jks` desde el respaldo a una carpeta fuera de su repositorio y se pone esa ruta en su `key.properties`.

`key.properties` está en `android/.gitignore`: **no se sube nunca**, ni al repositorio ni al grupo de WhatsApp ni a Taiga.

### Comprobar que el APK quedó firmado con ella

Con `apksigner`, que viene en el SDK de Android (`build-tools`; en Windows suele estar en `C:/Users/<usuario>/AppData/Local/Android/Sdk/build-tools/<version>/apksigner.bat`):

```powershell
apksigner verify --print-certs mobile/build/app/outputs/flutter-apk/app-release.apk
```

Tiene que mostrar `certificate DN: CN=FootwearPoint, ...` y `certificate SHA-256 digest: 960e8a79...2dfe8f`, la huella de arriba. Si dice `CN=Android Debug`, el APK salió con la llave de depuración y **no** sirve para publicar. Cuando no encuentra `key.properties`, el build también lo avisa en pantalla.

`keytool -printcert -jarfile` no sirve aquí: solo lee la firma vieja (v1), y estos APK van firmados con la v2, así que responde «no es un archivo jar firmado» aunque sí lo estén.

### Respaldo cifrado

Se hace con 7-Zip. Solo hay que rehacerlo si se pierde el de Drive, y en ese caso hay que actualizar su huella arriba:

```powershell
& "C:\Program Files\7-Zip\7z.exe" a -t7z -mhe=on -p "C:\Users\kevin\Documents\llaves-footwearpoint\footwearpoint-llave-respaldo.7z" "C:\Users\kevin\Documents\llaves-footwearpoint\footwearpoint.jks"
```

- `-p` sin valor hace que 7-Zip pida la contraseña sin mostrarla.
- Si ya existe un `.7z` con ese nombre, `7z a` **agrega** la llave al archivo existente en vez de crear uno nuevo: hay que borrarlo o renombrarlo antes.
- Al terminar, comprobar que la contraseña guardada lo abre: `7z t <archivo>` tiene que responder `Everything is Ok`.

### Prueba de restauración

1. Descargar el `.7z` **desde Drive**, no usar la copia local.
2. Comparar su huella con la de arriba (`Get-FileHash`).
3. Descomprimirlo en una carpeta temporal: `7z x <archivo> -o<carpeta temporal>`. Pide la contraseña del respaldo.
4. Comparar la huella del `.jks` restaurado con la de arriba.
5. Correr `keytool -list -v -keystore <carpeta temporal>\footwearpoint.jks`, que pide la contraseña de la llave. La línea `SHA256:` tiene que coincidir con la huella del certificado.
6. Borrar la carpeta temporal y el `.7z` descargado.

**Última prueba: 07/11/2026, superada.** La copia descargada de Drive era idéntica a la subida, el `.jks` restaurado era idéntico byte por byte al original, y `keytool` abrió la copia con la contraseña guardada y mostró la huella del certificado.

Hay que repetirla cuando cambie algo del esquema: otra carpeta de Drive, otras personas custodias, otras contraseñas o un respaldo rehecho.

### Ojo con el primer APK firmado de verdad

Todos los APK que se repartieron antes de TG-324 (de la 1.0.0+1 a la 1.3.x) salieron firmados con una llave de **depuración**, porque la del proyecto todavía no existía. Además, esa llave es distinta en cada computadora (`%USERPROFILE%\.android\debug.keystore`), así que un APK compilado en otra máquina tampoco se instalaba encima del anterior.

Por eso el primer APK firmado con la llave del proyecto **no** se va a instalar como actualización de ninguno de esos: quien ya tenga la app tiene que desinstalarla y volver a instalarla (pierde solo la sesión; los datos están en el servidor). Hay que avisarlo en las novedades de esa versión y, antes de subirla, confirmar que Uptodown acepta el cambio de firma en la misma ficha de la app.

Desde ese APK, **todos** los siguientes tienen que salir firmados con esta llave: uno firmado con la de depuración ya no se podría instalar como actualización.

## Publicar en Uptodown

La consola de desarrolladores está en <https://www.uptodown.dev/>. `developer.uptodown.com` **no** es la consola: es la ficha de otra app que se llama «Developer».

**Ficha pública:** se agrega aquí cuando Uptodown publique la app.

### Datos de la ficha

| Campo | Valor |
| --- | --- |
| Nombre | Footwear Point (en las tarjetas, Uptodown corta lo que pase de 30 caracteres) |
| Paquete | `com.footwearpoint.app`; tiene que ser el mismo en todas las versiones |
| Categoría | Estilo de vida → Compras |
| Ícono | `public/brand/logo-mark-512.png` (cuadrado, 512×512, PNG) |
| Imagen destacada | Opcional; de 1024×500 exactos |
| Descripción corta | Máximo 70 caracteres |
| Descripción larga | Mínimo 50 palabras; Uptodown recomienda 200 o más |
| Sitio oficial | <https://footwearpoint.app> |
| Licencia | Propietaria |
| Distribución | Gratis, sin compras dentro de la app, sin suscripción y sin anuncios |

- **Aviso de privacidad:** <https://footwearpoint.app/aviso-privacidad>. El formulario de Uptodown no tiene un campo propio para él, así que va al final de la descripción larga.
- **Que los editores de Uptodown no suban versiones por su cuenta:** marcar esa opción en la sección de versiones (en inglés, *Disable uploads by Uptodown editors*). Uptodown actualiza solo las apps cuando encuentra una versión nueva en otras fuentes; con la opción marcada, solo el equipo sube versiones, y todas salen firmadas con la llave del proyecto.

### Capturas

- Verticales, **al menos 4**, de la app tal como está hoy. Uptodown no fija un tamaño en píxeles: se toman a la resolución nativa del teléfono o del emulador (el Pixel 6 del emulador da 1080×2400), en PNG.
- **Sin datos de personas reales.** Se toman con la app apuntando al Laravel local con los datos de demostración (`DemoDistribuidoraSeeder` y `DemoContactosSeeder`), que son ficticios, con correos `.test`.
- Llevan texto en español, así que se suben con el idioma en español. Las que se suben en inglés se muestran en todos los idiomas; las que se suben en español, solo en español.

### Subir una versión

1. Subir el `versionCode` (el número después del `+` en `mobile/pubspec.yaml`) por encima de **todos** los que ya se repartieron. Si no es mayor que el de la última versión en Uptodown, la ficha la trata como versión vieja y no la ofrece como descarga principal.
2. Generar el APK con el comando de arriba y comprobar que la firma tenga la huella SHA-256 de la sección «Llave de firma».
3. En la consola: **Apps → Add new app** la primera vez; después, en la app, **Edit → Files → + Add new version**.
4. Escribir las **novedades** de la versión en español, en frases cortas y sin palabras técnicas: lo que la persona va a notar, no cómo se hizo.
5. Mandarla a revisión. Según la ayuda de Uptodown, una actualización suele quedar publicada en menos de 24 horas; una app nueva tarda más.
6. Subir **el mismo archivo** al APK de descarga directa (sección siguiente).
7. Ya publicada, revisar en la ficha pública la sección «Seguridad y privacidad» → **Firma del certificado**: tiene que decir `7645c804a1b3871bad0d76e36ac804b6`. Uptodown muestra ahí el **MD5** del certificado, no el SHA-256; es el mismo certificado de «Llave de firma», y `apksigner` lo da como `certificate MD5 digest`. El campo **SHA256** de «Información de descarga» es la huella del archivo APK, no la del certificado.

### Cambio de firma

La 1.3.3+8 es la primera versión firmada con la llave del proyecto. La ayuda de Uptodown para actualizar una app solo pide el mismo paquete y un `versionCode` mayor, y no dice nada de un cambio de firma. Si la app ya tenía versiones en Uptodown firmadas con la llave de depuración, hay que confirmar con el soporte para desarrolladores (botón *Contact Us* de la consola) que aceptan la firma nueva en la misma ficha **antes** de subirla. Si es la primera publicación en Uptodown, no hay una firma anterior con la cual chocar.

### Versiones publicadas

| Versión | Fecha | Firma | Huella del archivo (SHA-256) |
| --- | --- | --- | --- |
| 1.3.3+8 | Pendiente de publicar (con los arreglos de TG-410) | Llave del proyecto | `62270d842895a430ed124faf0c01dba7707b6bc02b4426e2f3617b07bb7e357a` |

## APK de descarga directa (R2)

El botón «Descargar app» del panel del admin general (`/admin/app-movil`) entrega `app-movil/footwearpoint.apk`, que vive en el bucket público de las fotos en R2:

```
https://pub-118e6637c4c64382aabc40a636c87a9e.r2.dev/app-movil/footwearpoint.apk
```

Ese archivo tiene que ser **siempre el mismo APK que está en Uptodown**. Si conviven uno firmado con la llave de depuración y otro con la del proyecto, quien instale uno no podrá actualizar con el otro.

### Reemplazarlo

1. En Cloudflare → **R2** → el bucket de las fotos → carpeta `app-movil/`.
2. Subir el APK nuevo **con el mismo nombre**, `footwearpoint.apk`, para que reemplace al anterior. No hace falta cambiar nada en el código ni en Railway: la ruta y el nombre siguen siendo los mismos.
3. Comprobarlo desde fuera, sin descargar el archivo:

   ```powershell
   curl.exe -sI https://pub-118e6637c4c64382aabc40a636c87a9e.r2.dev/app-movil/footwearpoint.apk
   ```

   - `Last-Modified` tiene que ser la fecha de la subida.
   - `Content-Type` tiene que ser `application/vnd.android.package-archive`.
   - `ETag` tiene que ser el MD5 del APK nuevo; para la 1.3.3+8, `70012736fda81c3421fcf4884356a204`. Si el `ETag` trae un guion (por ejemplo `…-4`), R2 lo subió en partes y ese valor no es el MD5: en ese caso hay que descargarlo y comparar su SHA-256 con el de la tabla de versiones.
   - **El tamaño no sirve para comprobar.** La 1.3.2+7 de depuración y la 1.3.3+8 pesan lo mismo: 56,174,812 bytes.

Antes de la 1.3.3+8, ese archivo era la 1.3.2+7 firmada con la llave de depuración (subida el 23/10/2026).

## Enlace de descarga en la web (`APP_MOVIL_DESCARGA_URL`)

Tres páginas que se abren desde un correo muestran los botones «Abrir la app» y «Descargar la app»: **correo verificado**, **restablecer contraseña** y **confirmar el cambio de correo o teléfono**.

- «Descargar la app» lleva al enlace de descarga.
- «Abrir la app» abre la app en el perfil si está instalada; si no lo está, Chrome manda al mismo enlace de descarga.

Ese enlace sale de `APP_MOVIL_DESCARGA_URL`. Cuando Uptodown publique la app, se pone ahí la URL de su ficha, en el servicio web de Railway (`footwearpoint` → **Variables**). Railway vuelve a desplegar al guardar la variable, y el despliegue regenera la caché de configuración. Los otros servicios no la necesitan.

Si la variable está vacía, el enlace se arma con el disco del APK (`APK_DISK`, que por omisión es `productos`), con la URL pública de `AWS_URL` (la misma de las fotos): `…r2.dev/app-movil/footwearpoint.apk`. Antes de TG-410 ese disco no tenía URL pública, y el enlace salía a la dirección privada de R2 (`…r2.cloudflarestorage.com/…`), que da error sin credenciales.

No cambian con la variable:

- El botón «Descargar app» del admin general, que descarga el archivo directo del bucket.
- La página de los enlaces que se comparten por WhatsApp (`/abrir/...`): sin la app instalada, manda a la página de inicio.

## Nombre e icono

- Nombre visible: «Footwear Point» (`android:label` en `mobile/android/app/src/main/AndroidManifest.xml`).
- Icono: el logo de la marca (`public/brand/logo-mark-512.png`) sobre círculo blanco, en `res/mipmap-*/ic_launcher.png`.
- Icono de los avisos: silueta blanca del logo en `res/drawable-*/ic_stat_notificacion.png`, con el azul de la marca (`res/values/colors.xml`). Android pinta los iconos de notificación de un solo color; si se usara el icono a color se vería un cuadro gris.

## Instalar

```powershell
adb install -r mobile\build\app\outputs\flutter-apk\app-release.apk
```

Si antes había un APK de depuración, Android puede rechazar la actualización por venir con otra firma: en ese caso, desinstalar primero (`adb uninstall com.footwearpoint.app`).

## Probar los avisos push

1. Iniciar sesión en la app. Desde Android 13 la app pide permiso para mostrar notificaciones: hay que **permitirlo**.
2. Al iniciar sesión, la app registra el celular con `POST /api/dispositivos-fcm` (TG-96).
3. Poner la app en segundo plano y provocar un aviso. Por ejemplo:
   - Que el cliente pague con Mercado Pago (aviso «Recibimos tu anticipo…»).
   - O que la distribuidora marque su pedido como recibido o listo para entrega.
4. El aviso aparece en la barra de notificaciones. Con la app abierta en primer plano Android no lo muestra en la barra, pero sí queda en la bandeja de la app.
