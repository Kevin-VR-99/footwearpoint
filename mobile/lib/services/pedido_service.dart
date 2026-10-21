import '../models/pagina.dart';
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

  /// "Mis pedidos": el servidor ya regresa solo los de quien inició sesión
  /// (PropietarioActual), del más nuevo al más viejo. Esta es la primera
  /// página (hasta 100); para ver más se usa [listarPagina].
  ///
  /// Sin borradores: son pedidos que nunca se enviaron (por ejemplo, si se
  /// cortó la conexión a medio envío) y desde la app no se pueden continuar.
  Future<List<PedidoResumen>> listar() async {
    return (await listarPagina(pagina: 1, porPagina: 100)).elementos;
  }

  /// Una página de "Mis pedidos" (auditoría 21-oct-2026: la API ya pagina).
  Future<Pagina<PedidoResumen>> listarPagina({int pagina = 1, int porPagina = 25}) async {
    final respuesta = await _api.get('pedidos?page=$pagina&per_page=$porPagina');
    final pagina0 = Pagina.desdeJson(respuesta, PedidoResumen.desdeJson);

    return Pagina(
      elementos: pagina0.elementos.where((p) => p.estado != 'borrador').toList(),
      paginaActual: pagina0.paginaActual,
      ultimaPagina: pagina0.ultimaPagina,
    );
  }

  /// El detalle, con líneas y pagos.
  Future<PedidoResumen> ver(int pedidoId) async {
    final respuesta = await _api.get('pedidos/$pedidoId');

    return PedidoResumen.desdeJson(respuesta['data'] as Map<String, dynamic>);
  }

  /// TG-241 (A12): la fábrica no surtió un producto y la persona dice qué
  /// prefiere: 'sustitucion' o 'reembolso_anticipo'.
  ///
  /// Esto NO mueve dinero: solo deja anotada su decisión. El efectivo se lo
  /// entrega la distribuidora en el mostrador.
  Future<String> resolverNoSurtido({
    required int pedidoId,
    required int lineaId,
    required String resolucion,
  }) async {
    final respuesta = await _api.post(
      'pedidos/$pedidoId/detalle/$lineaId/resolucion',
      cuerpo: {'resolucion': resolucion},
    );

    return respuesta['message'] as String? ?? 'Listo, quedó anotado.';
  }
}
