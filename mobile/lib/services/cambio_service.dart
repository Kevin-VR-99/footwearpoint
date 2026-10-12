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
}
