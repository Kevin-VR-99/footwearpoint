import 'package:flutter/foundation.dart';

/// Configuracion de la app. Un solo lugar para cambiar la direccion del
/// servidor, para no andarla buscando regada entre las pantallas.
class Config {
  /// Direccion base de la API de Laravel.
  ///
  /// Seccion 0.9 del plan del sprint: cada quien corre su PROPIA copia de
  /// Laravel en su propia computadora. Nadie depende de que la maquina de
  /// otro integrante este prendida.
  ///
  /// Que valor usar:
  ///
  ///   - EMULADOR de Android -> dejarlo como esta. La direccion 10.0.2.2 es
  ///     especial: dentro del emulador significa "esta misma computadora".
  ///     No hay que buscar ninguna IP.
  ///
  ///   - CELULAR FISICO -> usar la IP de red local de TU computadora, con el
  ///     celular en la misma WiFi, pasandola con --dart-define (ver abajo).
  ///     En Windows se averigua con "ipconfig" (busca "Direccion IPv4",
  ///     algo como 192.168.1.75).
  ///
  /// Y del lado de Laravel, el servidor se levanta asi:
  ///
  ///     php artisan serve --host=0.0.0.0
  ///
  /// Sin el --host, Laravel solo escucha en la propia computadora y el
  /// celular nunca lo va a alcanzar, aunque la IP este bien.
  ///
  /// IMPORTANTE: NO cambies la direccion en este archivo para probar con tu
  /// celular. Si se sube al repositorio, la app deja de conectar en la
  /// computadora de todos los demas (ya paso una vez). En su lugar, pasa tu
  /// IP al correr la app, sin tocar nada aqui:
  ///
  ///     flutter run --dart-define=API_URL=http://192.168.1.75:8000/api
  ///
  /// Sin ese --dart-define se usa 10.0.2.2 en Android (emulador) y
  /// 127.0.0.1 en iOS (el simulador de iOS ve la Mac directamente) (TG-183).
  static const String _urlDefinida = String.fromEnvironment('API_URL');

  static String get urlBaseApi {
    if (_urlDefinida.isNotEmpty) return _urlDefinida;
    return defaultTargetPlatform == TargetPlatform.iOS
        ? 'http://127.0.0.1:8000/api'
        : 'http://10.0.2.2:8000/api';
  }

  /// Plataforma que Laravel guarda con el token de Firebase
  /// (dispositivos_fcm.plataforma acepta android, ios o web).
  static String get plataformaFcm => switch (defaultTargetPlatform) {
        TargetPlatform.iOS => 'ios',
        _ => 'android',
      };
}
