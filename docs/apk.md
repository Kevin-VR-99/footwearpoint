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

Android solo deja **actualizar** una app instalada si la versión nueva viene firmada con la **misma** llave que la anterior. Si la llave se pierde no hay forma de recuperarla ni de volver a firmar: habría que publicar otra app y pedirle a cada persona que la instale de cero. Por eso la llave y sus contraseñas viven **fuera del repositorio** y se guardan aparte (gestor de contraseñas del equipo o unidad compartida del proyecto).

### Crearla (una sola vez, en todo el proyecto)

```powershell
cd mobile/android
keytool -genkeypair -v -keystore footwearpoint.jks -keyalg RSA -keysize 2048 -validity 10000 -alias footwearpoint
```

`keytool` viene con el JDK; si no lo encuentra, está en `C:/Program Files/Android/Android Studio/jbr/bin`.

Pide una contraseña (la misma sirve para el almacén y para la llave) y unos datos de identificación; con el nombre del equipo y la ciudad basta. `-validity 10000` son unos 27 años: tiene que durar más que la app.

### Configurarla

Copiar `mobile/android/key.properties.example` a `mobile/android/key.properties` y poner ahí la contraseña elegida:

```properties
storeFile=../footwearpoint.jks
storePassword=...
keyAlias=footwearpoint
keyPassword=...
```

`footwearpoint.jks` y `key.properties` ya están en `android/.gitignore`: **no se suben nunca**, ni al repositorio ni al grupo de WhatsApp ni a Taiga.

### Comprobar que el APK quedó firmado con ella

Con `apksigner`, que viene en el SDK de Android (`build-tools`; en Windows suele estar en `C:/Users/<usuario>/AppData/Local/Android/Sdk/build-tools/<version>/apksigner.bat`):

```powershell
apksigner verify --print-certs mobile/build/app/outputs/flutter-apk/app-release.apk
```

Tiene que mostrar el `CN` que se escribió al crear la llave. Si dice `CN=Android Debug`, el APK salió con la llave de depuración y **no** sirve para publicar.

`keytool -printcert -jarfile` no sirve aquí: solo lee la firma vieja (v1) y estos APK usan la v2/v3, así que responde «no es un archivo jar firmado» aunque sí esté firmado.

### Ojo con el primer APK firmado de verdad

El APK que está publicado hoy (1.0.0+1) salió firmado con la llave de **depuración**. En cuanto se publique uno firmado con la llave nueva, Android **no** lo va a aceptar como actualización de ese: quien ya lo tenga instalado va a tener que desinstalarlo y volverlo a instalar (pierde solo la sesión; los datos están en el servidor). Conviene hacer el cambio ahora, mientras casi nadie lo tiene instalado, y avisarlo en las novedades de la versión.

## Subir una versión nueva a Uptodown

1. Subir el `versionCode` (el número después del `+` en `mobile/pubspec.yaml`). Uptodown rechaza un APK con un número que ya subió.
2. Generar el APK con el comando de arriba y comprobar la firma.
3. En <https://developer.uptodown.com>, entrar a la app y subir el archivo nuevo.
4. Escribir las **novedades** de la versión en español, en frases cortas y sin palabras técnicas: lo que la persona va a notar, no cómo se hizo.
5. Uptodown revisa el archivo antes de publicarlo; tarda desde unas horas hasta un día.

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
