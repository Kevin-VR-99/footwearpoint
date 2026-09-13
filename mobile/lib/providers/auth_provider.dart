import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../models/usuario.dart';
import '../services/api_service.dart';

/// Guarda quién inició sesión y avisa a las pantallas cuando eso cambia.
///
/// Las pantallas no guardan el usuario por su cuenta: lo leen de aquí.
class AuthProvider extends ChangeNotifier {
  AuthProvider({ApiService? api}) : api = api ?? ApiService() {
    this.api.alNoAutorizado = _sesionVencida;
  }

  /// Se expone para que las demás pantallas hagan sus peticiones con el
  /// mismo token, sin crear su propio ApiService.
  final ApiService api;

  /// Además del token (que guarda ApiService), se guarda lo que respondió el
  /// login: usuario, rol y distribuidora. No hay un endpoint para volver a
  /// pedirlos, y sin ellos la app no sabe qué mostrarle a quien la abre.
  static const _llaveSesion = 'sesion_usuario';

  static const mensajeSesionVencida = 'Tu sesión expiró. Vuelve a iniciar sesión.';

  final _almacen = const FlutterSecureStorage();

  Usuario? _usuario;
  String? _rol;
  int? _distribuidoraId;
  bool _iniciando = true;
  bool _ocupado = false;
  String? _error;

  Usuario? get usuario => _usuario;
  String? get rol => _rol;
  int? get distribuidoraId => _distribuidoraId;
  bool get ocupado => _ocupado;
  String? get error => _error;
  bool get haySesion => _usuario != null;

  /// True mientras se lee la sesión guardada al abrir la app. Mientras dure,
  /// no se sabe todavía si toca mostrar el login o la app.
  bool get iniciando => _iniciando;

  /// Se llama una sola vez al abrir la app.
  ///
  /// No le pregunta nada al servidor: si el token guardado ya no sirve, la
  /// primera petición regresa 401 y [_sesionVencida] manda al login.
  Future<void> restaurarSesion() async {
    try {
      final token = await api.token();
      final guardada = await _almacen.read(key: _llaveSesion);

      if (token != null && token.isNotEmpty && guardada != null) {
        final datos = jsonDecode(guardada) as Map<String, dynamic>;

        _usuario = Usuario.desdeJson(datos['usuario'] as Map<String, dynamic>);
        _rol = datos['rol'] as String?;
        _distribuidoraId = datos['distribuidora_id'] as int?;
      } else if (token != null || guardada != null) {
        // Quedó solo una de las dos partes (por ejemplo, un token guardado
        // por la versión del Bloque 0, que no guardaba el usuario). Con
        // media sesión no se puede trabajar: se pide login de nuevo.
        await _olvidarSesion();
      }
    } catch (_) {
      // Lo guardado está dañado o no tiene la forma esperada. No se puede
      // confiar en ello: se empieza sin sesión.
      await _olvidarSesion();
    } finally {
      _iniciando = false;
      notifyListeners();
    }
  }

  /// Devuelve true si entró. Si no, el motivo queda en [error].
  Future<bool> login(String email, String password) async {
    _ocupado = true;
    _error = null;
    notifyListeners();

    try {
      final respuesta = await api.post(
        'auth/login',
        cuerpo: {'email': email, 'password': password},
      );

      final datos = respuesta['data'] as Map<String, dynamic>;

      await api.guardarToken(datos['token'] as String);

      _usuario = Usuario.desdeJson(datos['usuario'] as Map<String, dynamic>);
      _rol = datos['rol'] as String?;
      _distribuidoraId = datos['distribuidora_id'] as int?;

      await _guardarSesion();

      return true;
    } on ApiException catch (e) {
      // Laravel manda el "credenciales incorrectas" dentro de errors.email,
      // no en message. Si no viene ahí, se muestra el mensaje general.
      _error = e.errorDe('email') ?? e.mensaje;
      return false;
    } finally {
      _ocupado = false;
      notifyListeners();
    }
  }

  Future<void> logout() async {
    _ocupado = true;
    notifyListeners();

    try {
      await api.post('auth/logout');
    } on ApiException {
      // Aunque el servidor no conteste, la sesión local se cierra igual: no
      // tiene caso dejar al usuario atrapado dentro de la app.
    } finally {
      await _olvidarSesion();

      _error = null;
      _ocupado = false;

      notifyListeners();
    }
  }

  /// Lo llama ApiService cuando el servidor responde 401, desde cualquier
  /// pantalla. Al quedar sin usuario, main.dart muestra el login solo.
  void _sesionVencida() {
    // Sin sesión no hay nada que cerrar (por ejemplo, un 401 que llega
    // cuando ya se estaba en el login).
    if (!haySesion) return;

    unawaited(_olvidarSesion());

    _error = mensajeSesionVencida;
    notifyListeners();
  }

  Future<void> _guardarSesion() {
    return _almacen.write(
      key: _llaveSesion,
      value: jsonEncode({
        'usuario': _usuario!.aJson(),
        'rol': _rol,
        'distribuidora_id': _distribuidoraId,
      }),
    );
  }

  /// Borra la sesión de memoria y del teléfono.
  Future<void> _olvidarSesion() async {
    // Primero la memoria, sin esperar a nada: así [haySesion] cambia en ese
    // mismo instante, aunque borrar del teléfono tarde un poco.
    _usuario = null;
    _rol = null;
    _distribuidoraId = null;

    await api.borrarToken();
    await _almacen.delete(key: _llaveSesion);
  }
}
