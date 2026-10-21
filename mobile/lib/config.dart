/// Configuracion de la app. Un solo lugar para cambiar la direccion del
/// servidor, para no andarla buscando regada entre las pantallas.
class Config {
  /// Direccion base de la API de Laravel.
  ///
  /// Por omision apunta a PRODUCCION (https://footwearpoint.app/api): asi un
  /// `flutter build apk` sin parametros sale listo para la tienda y nunca se
  /// publica una app que apunte a una computadora local.
  ///
  /// Para desarrollar contra tu propio Laravel, pasa la direccion al correr
  /// la app, sin tocar este archivo:
  ///
  ///   - EMULADOR de Android (10.0.2.2 = "esta misma computadora"):
  ///       flutter run --dart-define=API_URL=http://10.0.2.2:8000/api
  ///
  ///   - CELULAR FISICO en la misma WiFi (la IPv4 de tu computadora):
  ///       flutter run --dart-define=API_URL=http://192.168.1.75:8000/api
  ///
  /// Y Laravel se levanta con `php artisan serve --host=0.0.0.0`; sin el
  /// --host el celular nunca lo alcanza.
  static const String urlBaseApi = String.fromEnvironment(
    'API_URL',
    defaultValue: 'https://footwearpoint.app/api',
  );
}
