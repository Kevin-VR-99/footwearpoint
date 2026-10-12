# App de FootwearPoint en iOS (TG-183)

La carpeta `ios/` ya está lista (bundle id, nombre, icono, permisos, push), pero
**una app de iOS solo se puede compilar en una Mac con Xcode** (o en un servicio
en la nube con Mac, como Codemagic). Desde Windows o Linux se puede programar,
correr `flutter analyze` y `flutter test`, pero no generar el `.ipa`.

## Datos del proyecto

| Cosa | Valor |
|---|---|
| Bundle id | `com.footwearpoint.app` (el mismo que el `applicationId` de Android) |
| Nombre visible | FootwearPoint |
| iOS mínimo | 15.0 (lo pide Firebase iOS SDK 12, que usan firebase_core 4 y firebase_messaging 16) |
| Capabilities | Push Notifications y Background Modes → Remote notifications (`Runner/Runner.entitlements`) |
| Icono | `Runner/Assets.xcassets/AppIcon.appiconset`, sacado de `public/brand/logo-mark.png` |

Permisos: la app hoy no usa cámara, fotos ni ubicación (no hay plugins para eso),
así que `Info.plist` no lleva textos de `NSCameraUsageDescription`,
`NSPhotoLibraryUsageDescription` ni `NSLocationWhenInUseUsageDescription`.
Si se agrega uno de esos plugins, hay que poner su texto en español en
`ios/Runner/Info.plist`, o Apple rechaza la app (y iOS la cierra al pedir el permiso).

Mercado Pago: igual que en Android, el pago se abre en el navegador
(`url_launcher`, `LaunchMode.externalApplication`) y la persona regresa a la app
sola; no hay URL scheme propio. Por eso `Info.plist` solo declara
`LSApplicationQueriesSchemes` con `https` y `http`. Si algún día se usa un deep
link de regreso, se agrega `CFBundleURLTypes` aquí y el `<intent-filter>` en Android.

## 1. GoogleService-Info.plist (no se sube al repositorio)

1. En la consola de Firebase, proyecto de FootwearPoint → *Configuración del proyecto*
   → *Tus apps* → *Agregar app* → iOS, con bundle id `com.footwearpoint.app`.
2. Descarga `GoogleService-Info.plist` y ponlo en
   **`mobile/ios/Runner/GoogleService-Info.plist`**.
3. Ya está referenciado en el proyecto de Xcode (target Runner, Copy Bundle Resources)
   y en `ios/.gitignore`, así que **no se sube**. Sin ese archivo la compilación de iOS
   falla con "GoogleService-Info.plist not found".

## 2. Llave APNs en Firebase (para que lleguen los push)

Solo con cuenta de pago de Apple Developer:

1. developer.apple.com → *Certificates, IDs & Profiles* → *Keys* → crea una llave con
   **Apple Push Notifications service (APNs)** y descarga el `.p8` (solo se baja una vez).
   Anota el *Key ID* y tu *Team ID*.
2. Firebase → *Configuración del proyecto* → *Cloud Messaging* → app de iOS →
   *Llave de autenticación de APNs* → sube el `.p8` con el Key ID y el Team ID.
3. El `.p8` nunca va al repositorio ni a ningún archivo del proyecto.

## 3. Cuenta gratis contra cuenta de pago

| | Apple ID gratis | Apple Developer Program (99 USD/año) |
|---|---|---|
| Instalar en tu propio iPhone desde Xcode | Sí | Sí |
| Duración de la instalación | 7 días, luego hay que reinstalar | 1 año |
| Notificaciones push (APNs) | **No** (la capability Push no se puede firmar) | Sí |
| TestFlight / App Store | No | Sí |
| Codemagic firmando en la nube | No práctico | Sí |

Con cuenta gratis, para compilar quita temporalmente *Push Notifications* en
Xcode → Runner → *Signing & Capabilities* (o borra `CODE_SIGN_ENTITLEMENTS`), sin
subir ese cambio. La app funciona, solo que sin push.

## 4. Compilar en una Mac con Xcode

```bash
cd mobile
flutter pub get
open ios/Runner.xcworkspace        # siempre el .xcworkspace
```

En Xcode: *Runner* → *Signing & Capabilities* → elige tu *Team* (deja el bundle id
`com.footwearpoint.app`; si Apple dice que ya está tomado en una cuenta gratis, usa
uno tuyo solo en local). Luego:

```bash
# simulador (Laravel en la misma Mac)
flutter run -d ios
# iPhone conectado por cable, apuntando al servidor
flutter run -d <id-del-iphone> --release --dart-define=API_URL=https://TU-SERVIDOR/api
# archivo para TestFlight / App Store
flutter build ipa --release --dart-define=API_URL=https://TU-SERVIDOR/api
```

Flutter usa Swift Package Manager para los plugins; si algún plugin todavía pide
CocoaPods, se usa `ios/Podfile` (ya con `platform :ios, '15.0'`) y antes hay que
correr `cd ios && pod install`.

## 5. Compilar con Codemagic (sin Mac)

1. Conecta el repositorio en codemagic.io y elige la rama.
2. Sube `GoogleService-Info.plist` como *archivo seguro* (variable de entorno de
   tipo archivo) y en un *pre-build script* cópialo a `mobile/ios/Runner/`.
3. En *iOS code signing* conecta tu cuenta de Apple Developer (llave de App Store
   Connect API) con firma automática para `com.footwearpoint.app`.
4. Directorio del proyecto: `mobile`; comando `flutter build ipa --release
   --dart-define=API_URL=https://TU-SERVIDOR/api`.
5. Publica en TestFlight desde Codemagic.

## 6. Instalar en el iPhone

- **Desde Xcode** (gratis o de pago): iPhone por cable, *Modo desarrollador* activado
  (Ajustes → Privacidad y seguridad), botón ▶. Con cuenta gratis, en el iPhone ve a
  Ajustes → General → VPN y gestión de dispositivos → confía en tu Apple ID.
- **TestFlight** (de pago): sube el `.ipa`, agrega a los probadores y ellos instalan
  la app TestFlight y aceptan la invitación.

## Lo que cambió en el código Dart

- `Config.urlBaseApi`: sin `--dart-define=API_URL` usa `10.0.2.2` en Android y
  `127.0.0.1` en iOS.
- `Config.plataformaFcm`: el token de Firebase se registra en Laravel como `ios` o
  `android` según el teléfono (antes siempre decía `android`).
