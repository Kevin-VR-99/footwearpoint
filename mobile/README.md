# FootwearPoint — app móvil

App Flutter de FootwearPoint para revendedores y clientes directos: catálogo, carrito y pedidos, vales, solicitudes de cambio, recolección en sucursal y avisos push.

Consume la API del panel Laravel que vive en la raíz de este repositorio. El contrato está en [`../docs/contrato-api.md`](../docs/contrato-api.md).

> **Software propietario. Todos los derechos reservados.** Revisa [`../LICENSE`](../LICENSE). Las librerías de Flutter y Dart que usa la app conservan su propia licencia.

## Qué necesitas

- Flutter estable (probado con 3.47.7), con Dart SDK `^3.13.3`
- Android SDK, y un emulador o un teléfono físico
- El panel Laravel corriendo, si vas a desarrollar contra tu propia máquina

## Correrla en local

```bash
cd mobile
flutter pub get
```

La app toma la dirección de la API de `--dart-define=API_URL=...`. **Sin ese parámetro apunta a producción** (`https://footwearpoint.app/api`), y es a propósito: así un `flutter build apk` sin argumentos nunca sale apuntando a una computadora local. Está en `lib/config.dart`.

Contra tu Laravel local, en el emulador de Android (`10.0.2.2` es "esta misma computadora"):

```bash
flutter run --dart-define=API_URL=http://10.0.2.2:8000/api
```

Desde un teléfono físico en la misma WiFi, con la IPv4 de tu computadora:

```bash
flutter run --dart-define=API_URL=http://192.168.1.75:8000/api
```

En los dos casos Laravel se levanta con `php artisan serve --host=0.0.0.0`. Sin el `--host`, el emulador o el teléfono nunca lo alcanzan.

## Pruebas

```bash
flutter test
```

## Estructura

| Carpeta | Contenido |
| --- | --- |
| `lib/screens/` | Pantallas |
| `lib/widgets/` | Componentes reutilizables |
| `lib/providers/` | Estado, con `provider` |
| `lib/services/` | Llamadas a la API y guardado del token |
| `lib/models/` | Modelos de datos |
| `lib/tema/` | Tema visual |
| `lib/config.dart` | Dirección de la API |
| `lib/validaciones.dart` | Validaciones de formularios |
| `test/` | Pruebas de widgets y de providers |

## Dependencias principales

- `http` para las llamadas a la API
- `provider` para el manejo de estado
- `flutter_secure_storage` para guardar cifrado el token de Sanctum
- `firebase_core` y `firebase_messaging` para los avisos push
- `app_links` para abrir un pedido o un producto desde un enlace
- `cached_network_image` para las fotos de producto, con caché en disco y memoria
- `url_launcher` para abrir el pago de Mercado Pago en el navegador
- `image_picker` para la foto de perfil del revendedor

## APK y versiones

Para generar el APK, firmarlo y repartirlo, sigue [`../docs/apk.md`](../docs/apk.md).

El número de versión sale de `version:` en `pubspec.yaml`, con el formato `nombre+versionCode`. Hay que subir el número que va después del `+` en cada APK que se reparta, o Android no lo acepta como actualización.

La llave de firma y sus contraseñas viven **fuera del repositorio**. Si se pierde, la app instalada ya no se puede actualizar: habría que publicar otra y pedir que se instale de cero.
