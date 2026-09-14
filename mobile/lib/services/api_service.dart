import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;

import '../config.dart';

/// Un error al hablar con la API: puede venir del servidor (422, 403, 404...)
/// o de la red (no hay conexión, tardó demasiado).
class ApiException implements Exception {
  ApiException(this.mensaje, {this.codigoHttp, this.errores = const {}});

  final String mensaje;
  final int? codigoHttp;

  /// Errores de validación de Laravel, tal como los manda: el nombre del
  /// campo y la lista de mensajes de ese campo.
  final Map<String, List<String>> errores;

  /// Primer mensaje de error de un campo, o null si ese campo no falló.
  String? errorDe(String campo) => errores[campo]?.first;

  @override
  String toString() => mensaje;
}

/// El único lugar de la app que habla con Laravel.
///
/// Ninguna pantalla debe llamar al paquete http directamente (convención 1.4
/// del plan del sprint): todas pasan por aquí, para que el token, la
/// dirección del servidor y el manejo de errores vivan en un solo sitio.
class ApiService {
  ApiService({http.Client? cliente}) : _cliente = cliente ?? http.Client();

  final http.Client _cliente;

  static const _llaveToken = 'token_sanctum';
  static const _tiempoLimite = Duration(seconds: 15);

  final _almacen = const FlutterSecureStorage();

  /// Copia en memoria para no ir al almacenamiento seguro en cada petición.
  String? _token;

  /// Se llama cuando el servidor responde 401: el token ya no sirve (se
  /// cerró la sesión en otro lado o lo borraron en el servidor). Lo registra
  /// AuthProvider para sacar al usuario al login desde cualquier pantalla,
  /// sin que cada pantalla tenga que revisar el 401 por su cuenta.
  void Function()? alNoAutorizado;

  Future<String?> token() async {
    _token ??= await _almacen.read(key: _llaveToken);
    return _token;
  }

  Future<void> guardarToken(String token) async {
    _token = token;
    await _almacen.write(key: _llaveToken, value: token);
  }

  Future<void> borrarToken() async {
    _token = null;
    await _almacen.delete(key: _llaveToken);
  }

  Future<Map<String, dynamic>> get(String ruta) async {
    return _enviar(() async => _cliente.get(_url(ruta), headers: await _encabezados()));
  }

  Future<Map<String, dynamic>> post(String ruta, {Map<String, dynamic>? cuerpo}) async {
    return _enviar(
      () async => _cliente.post(
        _url(ruta),
        headers: await _encabezados(),
        body: jsonEncode(cuerpo ?? const <String, dynamic>{}),
      ),
    );
  }

  Future<Map<String, dynamic>> patch(String ruta, {Map<String, dynamic>? cuerpo}) async {
    return _enviar(
      () async => _cliente.patch(
        _url(ruta),
        headers: await _encabezados(),
        body: jsonEncode(cuerpo ?? const <String, dynamic>{}),
      ),
    );
  }

  /// Acepta la ruta con o sin diagonal al inicio: 'auth/login' y
  /// '/auth/login' llegan al mismo lugar.
  Uri _url(String ruta) {
    final limpia = ruta.startsWith('/') ? ruta.substring(1) : ruta;
    return Uri.parse('${Config.urlBaseApi}/$limpia');
  }

  Future<Map<String, String>> _encabezados() async {
    final encabezados = <String, String>{
      'Accept': 'application/json',
      'Content-Type': 'application/json',
    };

    final actual = await token();
    if (actual != null && actual.isNotEmpty) {
      encabezados['Authorization'] = 'Bearer $actual';
    }

    return encabezados;
  }

  Future<Map<String, dynamic>> _enviar(Future<http.Response> Function() peticion) async {
    final http.Response respuesta;

    try {
      respuesta = await peticion().timeout(_tiempoLimite);
    } on SocketException {
      // El error más común del equipo mientras prueba, por eso el mensaje
      // dice qué revisar en vez de solo "fallo la conexión".
      throw ApiException(
        'No se pudo conectar con el servidor.\n\n'
        'Revisa que Laravel esté corriendo con:\n'
        'php artisan serve --host=0.0.0.0\n\n'
        'Y que la dirección en lib/config.dart sea la correcta '
        '(10.0.2.2 para emulador, la IP de tu computadora para celular físico).',
      );
    } on TimeoutException {
      throw ApiException('El servidor tardó demasiado en responder.');
    }

    return _procesar(respuesta);
  }

  Map<String, dynamic> _procesar(http.Response respuesta) {
    var cuerpo = <String, dynamic>{};

    if (respuesta.body.isNotEmpty) {
      try {
        final decodificado = jsonDecode(respuesta.body);
        if (decodificado is Map<String, dynamic>) {
          cuerpo = decodificado;
        }
      } on FormatException {
        // Suele pasar cuando Laravel devuelve una página de error HTML en
        // vez de JSON: casi siempre es un error 500 del backend.
        throw ApiException(
          'El servidor respondió algo que no es JSON (código ${respuesta.statusCode}). '
          'Revisa storage/logs/laravel.log.',
          codigoHttp: respuesta.statusCode,
        );
      }
    }

    if (respuesta.statusCode >= 200 && respuesta.statusCode < 300) {
      return cuerpo;
    }

    if (respuesta.statusCode == 401) {
      alNoAutorizado?.call();
    }

    throw ApiException(
      cuerpo['message'] as String? ?? 'Error ${respuesta.statusCode}.',
      codigoHttp: respuesta.statusCode,
      errores: _erroresDe(cuerpo),
    );
  }

  /// Laravel manda los errores de validación así:
  ///   { "message": "...", "errors": { "email": ["mensaje"] } }
  Map<String, List<String>> _erroresDe(Map<String, dynamic> cuerpo) {
    final crudos = cuerpo['errors'];

    if (crudos is! Map) return const {};

    return crudos.map(
      (campo, mensajes) => MapEntry(
        campo.toString(),
        mensajes is List
            ? mensajes.map((m) => m.toString()).toList()
            : <String>[mensajes.toString()],
      ),
    );
  }
}
