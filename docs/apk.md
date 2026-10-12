# APK de la app móvil (TG-183)

Cómo generar el APK que apunta al servidor en producción (Railway) y cómo comprobar que llegan los avisos push.

## Qué necesita

- Flutter estable (probado con 3.47.7) y Android SDK.
- `mobile/android/app/google-services.json` del proyecto de Firebase `footwearpoint`, registrado para el paquete `com.footwearpoint.app`. Ya está en el repositorio: es configuración del cliente, no un secreto.
- El servidor necesita `FIREBASE_CREDENTIALS` (la llave de la cuenta de servicio). Vive solo en Railway; **nunca** se sube al repositorio.

## Dirección del servidor

La app toma la dirección de la API de `--dart-define=API_URL=...` (ver `mobile/lib/config.dart`). Sin ese parámetro usa `http://10.0.2.2:8000/api`, que es para el emulador con Laravel local.

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

- **Firma:** por ahora el APK de release se firma con la llave de depuración (`signingConfig = debug` en `android/app/build.gradle.kts`). Sirve para instalarlo en celulares y en el emulador, pero no para la Play Store. Si algún día se publica, se crea un keystore y se configura con `key.properties`; **nunca** se suben el keystore ni sus contraseñas.
- **Versión:** sale de `version:` en `mobile/pubspec.yaml` (`nombre+versionCode`). Hay que subir el número después del `+` en cada APK que se reparta, para que Android lo acepte como actualización.

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
