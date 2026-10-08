import '../models/notificacion.dart';
import 'api_service.dart';

/// La bandeja de notificaciones (E16-01; TG-165). El servidor solo regresa y
/// solo deja marcar las del usuario que inició sesión.
class NotificacionService {
  const NotificacionService(this._api);

  final ApiService _api;

  /// Las 50 más recientes (límite del servidor).
  Future<List<Notificacion>> listar() async {
    final respuesta = await _api.get('notificaciones');

    return [
      for (final n in respuesta['data'] as List<dynamic>? ?? const [])
        Notificacion.desdeJson(n as Map<String, dynamic>),
    ];
  }

  /// Para el globito de la campana.
  Future<int> contarNoLeidas() async {
    final respuesta = await _api.get('notificaciones?solo_no_leidas=1');
    final datos = respuesta['data'];

    // Si llegara algo con otra forma, la campana solo se queda sin número:
    // no vale la pena tumbar la pantalla de inicio por el globito.
    return datos is List ? datos.length : 0;
  }

  Future<void> marcarLeida(int id) async {
    await _api.post('notificaciones/$id/marcar-leida');
  }
}
