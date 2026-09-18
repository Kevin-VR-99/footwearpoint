import '../models/pedido_resumen.dart';
import 'api_service.dart';

/// Una línea para un pedido nuevo. Sin precio: lo decide el servidor según el
/// tipo de pedido (TG-166).
typedef LineaPedidoNueva = ({int productoCampanaId, int varianteId, int cantidad});

/// Crear y consultar pedidos desde la app (E8-01, E9-03; TG-165).
///
/// Es el único lugar que arma un pedido: lo usan el pedido directo y el
/// pedido acumulado del revendedor, para que los dos sigan los mismos pasos.
class PedidoService {
  const PedidoService(this._api);

  final ApiService _api;

  /// Crea el pedido en borrador, le agrega las líneas y lo envía. Regresa el
  /// pedido ya enviado, con los importes que calculó el servidor.
  ///
  /// No se manda tipo, dueño, sucursal ni precio: cuando el pedido lo crea un
  /// revendedor o cliente desde la app, el servidor los pone solo según la
  /// sesión e ignora lo que venga (TG-166).
  Future<PedidoResumen> crearYEnviar({required List<LineaPedidoNueva> lineas}) async {
    final creado = await _api.post('pedidos');

    final pedidoId = (creado['data'] as Map<String, dynamic>)['id'] as int;

    for (final linea in lineas) {
      await _api.post('pedidos/$pedidoId/lineas', cuerpo: {
        'producto_campana_id': linea.productoCampanaId,
        'variante_id': linea.varianteId,
        'cantidad': linea.cantidad,
      });
    }

    final enviado = await _api.post('pedidos/$pedidoId/enviar');

    return PedidoResumen.desdeJson(enviado['data'] as Map<String, dynamic>);
  }

  Future<PedidoResumen> ver(int pedidoId) async {
    final respuesta = await _api.get('pedidos/$pedidoId');

    return PedidoResumen.desdeJson(respuesta['data'] as Map<String, dynamic>);
  }
}
