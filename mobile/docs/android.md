# google-services.json para compilar el APK (TG-183)

Desde TG-183 `mobile/android/app/google-services.json` **ya no se sube al
repositorio** (está en `mobile/android/.gitignore`), igual que
`ios/Runner/GoogleService-Info.plist` en iOS (ver `ios.md`). Sin ese archivo el
plugin `com.google.gms.google-services` detiene la compilación con
*"File google-services.json is missing"*.

## Dónde ponerlo

```
mobile/android/app/google-services.json
```

## Cómo conseguirlo

1. Consola de Firebase → proyecto `footwearpoint` → *Configuración del proyecto*.
2. *Tus apps* → la app de Android `com.footwearpoint.app` → *Descargar google-services.json*.
3. Guárdalo en la ruta de arriba. `git status` no debe mostrarlo.

Si el paquete no coincide con `com.footwearpoint.app` (el `applicationId` de
`android/app/build.gradle.kts`), Firebase no conecta y los push no llegan.

## Compilar

```powershell
cd mobile
flutter pub get
flutter build apk --debug
flutter build apk --release --dart-define=API_URL=https://footwearpoint.app/api
```

Más detalles del APK de producción en `docs/apk.md` (raíz del repositorio).

## Si algún día hay CI (GitHub Actions, Codemagic)

Hoy el repositorio no tiene CI ni scripts que compilen la app. Si se agrega:

- Guarda el contenido del archivo en un secret, por ejemplo `GOOGLE_SERVICES_JSON`
  (en base64 para no batallar con saltos de línea).
- Antes de `flutter build apk`, recréalo:

  ```bash
  echo "$GOOGLE_SERVICES_JSON" | base64 -d > mobile/android/app/google-services.json
  ```

- En Codemagic también sirve subirlo como *archivo seguro* y copiarlo en un
  *pre-build script* a `mobile/android/app/`.

## Nota sobre el historial

El archivo se quitó del repositorio con `git rm --cached`, pero **sigue en el
historial de git** (commits anteriores a TG-183). No se reescribió el historial.
Es configuración del cliente de Firebase (no es la llave del servidor), pero si se
quiere rotar, se hace desde la consola de Firebase/Google Cloud (restringir o
regenerar la API key de Android) y se descarga uno nuevo.
