import 'dart:async';

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

  static const mensajeSesionVencida = 'Tu sesión expiró. Vuelve a iniciar sesión.';

  /// Llave donde una versión anterior de esta rama guardaba usuario, rol y
  /// distribuidora en el teléfono. Ya no se guarda nada ahí (ver
  /// [restaurarSesion]); solo se borra por si quedó en algún teléfono de prueba.
  static const _llaveSesionVieja = 'sesion_usuario';

  final _almacen = const FlutterSecureStorage();

  Usuario? _usuario;
  String? _rol;
  int? _distribuidoraId;
  bool _iniciando = true;
  bool _sinConexion = false;
  bool _ocupado = false;
  String? _error;

  Usuario? get usuario => _usuario;
  String? get rol => _rol;
  int? get distribuidoraId => _distribuidoraId;
  bool get ocupado => _ocupado;
  String? get error => _error;
  bool get haySesion => _usuario != null;

  /// La cuenta es válida pero no tiene distribuidora: su afiliación está
  /// suspendida (revendedor_distribuidora.estado) o la cuenta quedó mal
  /// ligada. El backend ya no le deja ver nada; la app tampoco la deja pasar.
  /// No se borra el token: si la reactivan, basta con volver a revisar.
  bool get sinAcceso => haySesion && _distribuidoraId == null;

  /// True mientras se revisa, al abrir la app, si el token guardado sirve.
  /// Mientras dure, no se sabe todavía si toca mostrar el login o la app.
  bool get iniciando => _iniciando;

  /// True si al abrir la app había token pero no se pudo hablar con el
  /// servidor para revisarlo. El token NO se borra: puede ser solo que no
  /// hay WiFi, y se vuelve a intentar con [reintentar].
  bool get sinConexion => _sinConexion;

  /// Se llama una sola vez al abrir la app.
  ///
  /// En el teléfono solo vive el token. Usuario, rol y distribuidora se le
  /// piden al servidor con GET auth/me: si se guardaran en el teléfono y un
  /// admin suspendiera a alguien, la app seguiría creyendo que tiene acceso.
  Future<void> restaurarSesion() async {
    try {
      await _almacen.delete(key: _llaveSesionVieja);

      final token = await api.token();
      if (token == null || token.isEmpty) return;

      final respuesta = await api.get('auth/me');
      _llenarSesion(respuesta['data'] as Map<String, dynamic>);
    } on ApiException catch (e) {
      if (e.codigoHttp == null) {
        // Sin conexión o el servidor tardó: no se sabe si el token sirve.
        _sinConexion = true;
        _error = e.mensaje;
      } else {
        // El servidor sí contestó y no reconoció el token: 401 (vencido o
        // cuenta suspendida) o cualquier otro error (404, 500).
        await _olvidarSesion();
        _error = e.codigoHttp == 401 ? mensajeSesionVencida : null;
      }
    } catch (_) {
      // El servidor contestó 200 pero con una forma que no se esperaba. No
      // se puede armar la sesión con eso: se pide login de nuevo.
      await _olvidarSesion();
    } finally {
      _iniciando = false;
      notifyListeners();
    }
  }

  /// El botón "Reintentar" de la pantalla sin conexión.
  Future<void> reintentar() async {
    _sinConexion = false;
    _iniciando = true;
    _error = null;
    notifyListeners();

    await restaurarSesion();
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
      _llenarSesion(datos);

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

  /// Reemplaza los datos del usuario con los que regresó el servidor (por
  /// ejemplo, al guardar el perfil), para que el nombre nuevo se vea en toda
  /// la app sin volver a iniciar sesión. Rol y distribuidora no cambian.
  void actualizarUsuario(Usuario usuario) {
    if (!haySesion) return;

    _usuario = usuario;
    notifyListeners();
  }

  /// Lo llama ApiService cuando el servidor responde 401, desde cualquier
  /// pantalla. Al quedar sin usuario, main.dart muestra el login solo.
  ///
  /// NO llama a [logout]: logout le pega al servidor, que respondería 401
  /// otra vez y se ciclaría.
  void _sesionVencida() {
    // Sin sesión no hay nada que cerrar (por ejemplo, el 401 de auth/me al
    // abrir la app, que ya atiende restaurarSesion).
    if (!haySesion) return;

    unawaited(_olvidarSesion());

    _error = mensajeSesionVencida;
    notifyListeners();
  }

  /// Misma forma en el login y en auth/me: { usuario, rol, distribuidora_id }.
  void _llenarSesion(Map<String, dynamic> datos) {
    // Se lee todo antes de asignar, para no quedar con media sesión si algún
    // campo no viene como se esperaba.
    final usuario = Usuario.desdeJson(datos['usuario'] as Map<String, dynamic>);
    final rol = datos['rol'] as String?;
    final distribuidoraId = datos['distribuidora_id'] as int?;

    _usuario = usuario;
    _rol = rol;
    _distribuidoraId = distribuidoraId;
  }

  /// Borra la sesión de memoria y el token del teléfono.
  Future<void> _olvidarSesion() async {
    // Primero la memoria, sin esperar a nada: así [haySesion] cambia en ese
    // mismo instante, aunque borrar del teléfono tarde un poco.
    _usuario = null;
    _rol = null;
    _distribuidoraId = null;

    await api.borrarToken();
  }
}
