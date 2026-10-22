import '../models/solicitud_cambio.dart';
import 'api_service.dart';

/// TG-254 (E12-01): pedir el cambio de un producto entregado. Las reglas
/// (plazo, que sea suyo, una solicitud por producto) las aplica el servidor;
/// si algo no se puede, responde con un mensaje listo para mostrar.
class CambioService {
  CambioService(this._api);

  final ApiService _api;

  Future<String> solicitar({required int pedidoDetalleId, required String motivo}) async {
    final respuesta = await _api.post(
      'solicitudes-cambio',
      cuerpo: {'pedido_detalle_id': pedidoDetalleId, 'motivo': motivo},
    );
    return respuesta['message'] as String? ?? 'Solicitud de cambio enviada.';
  }

  /// Las solicitudes de quien inició sesión (el servidor solo regresa las suyas).
  Future<List<SolicitudCambio>> listar() async {
    final respuesta = await _api.get('solicitudes-cambio');
    return [
      for (final s in respuesta['data'] as List<dynamic>? ?? const [])
        if (s is Map<String, dynamic>) SolicitudCambio.desdeJson(s),
    ];
  }

  /// Una solicitud con su línea de tiempo.
  Future<SolicitudCambio> detalle(int id) async {
    final respuesta = await _api.get('solicitudes-cambio/$id');
    return SolicitudCambio.desdeJson(respuesta['data'] as Map<String, dynamic>);
  }
}
