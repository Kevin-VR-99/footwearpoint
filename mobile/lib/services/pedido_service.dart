import '../models/pedido_resumen.dart';
import 'api_service.dart';

/// Sucursal a la que se mandan los pedidos desde la app.
///
/// PENDIENTE (Kevin, #166): el servidor va a poner solo la sucursal principal
/// y el propietario cuando el pedido lo crea un revendedor o cliente desde la
/// app, e ignorará lo que se mande. Mientras tanto se manda 1, que funciona
/// con los datos demo. Cuando eso llegue a main, se quitan esta constante y
/// los campos sucursal_id y propietario_id de [PedidoService.crearYEnviar].
const sucursalPedidosApp = 1;

/// Una línea para un pedido nuevo. Sin precio: lo decide el servidor según el
/// tipo de pedido (Kevin, #166).
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
  /// [tipo] es 'cliente_directo' o 'revendedor'. El servidor ya lo toma de la
  /// sesión para revendedor y cliente directo; se manda porque el endpoint lo
  /// exige (lo comparte con la web, donde el empleado sí lo elige).
  Future<PedidoResumen> crearYEnviar({
    required String tipo,
    required List<LineaPedidoNueva> lineas,
  }) async {
    final creado = await _api.post('pedidos', cuerpo: {
      'tipo': tipo,
      'propietario_id': 1,
      'sucursal_id': sucursalPedidosApp,
    });

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
