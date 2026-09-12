import 'package:flutter/foundation.dart';

import '../models/usuario.dart';
import '../services/api_service.dart';

/// Guarda quién inició sesión y avisa a las pantallas cuando eso cambia.
///
/// Las pantallas no guardan el usuario por su cuenta: lo leen de aquí.
class AuthProvider extends ChangeNotifier {
  AuthProvider({ApiService? api}) : api = api ?? ApiService();

  /// Se expone para que las demás pantallas hagan sus peticiones con el
  /// mismo token, sin crear su propio ApiService.
  final ApiService api;

  Usuario? _usuario;
  String? _rol;
  int? _distribuidoraId;
  bool _ocupado = false;
  String? _error;

  Usuario? get usuario => _usuario;
  String? get rol => _rol;
  int? get distribuidoraId => _distribuidoraId;
  bool get ocupado => _ocupado;
  String? get error => _error;
  bool get haySesion => _usuario != null;

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
      await api.borrarToken();

      _usuario = null;
      _rol = null;
      _distribuidoraId = null;
      _error = null;
      _ocupado = false;

      notifyListeners();
    }
  }
}
