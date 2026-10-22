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

  /// Cifras del inicio. El servidor las cuenta todas, no solo las primeras 100.
  Future<ResumenPedidos> resumen() async {
    final respuesta = await _api.get('pedidos/resumen');

    return ResumenPedidos.desdeJson(respuesta);
  }

  /// "Mis pedidos": el servidor ya regresa solo los de quien inició sesión
  /// (PropietarioActual), del más nuevo al más viejo.
  ///
  /// [excluirBorrador] pide al servidor que no mande borradores, para que una
  /// página no quede vacía. [estado] arma la lista de "Sin enviar".
  Future<Pagina<PedidoResumen>> listarPagina({
    int pagina = 1,
    int porPagina = 25,
    bool excluirBorrador = true,
    String? estado,
  }) async {
    final parametros = <String, String>{
      'page': '$pagina',
      'per_page': '$porPagina',
      'estado': ?estado,
      if (excluirBorrador && estado == null) 'excluir_borrador': '1',
    };
    final respuesta = await _api.get('pedidos?${Uri(queryParameters: parametros).query}');

    return Pagina.desdeJson(respuesta, PedidoResumen.desdeJson);
  }

  /// Quita un producto de un pedido que todavía no se envía.
  Future<PedidoResumen> quitarLinea({required int pedidoId, required int lineaId}) async {
    final respuesta = await _api.delete('pedidos/$pedidoId/lineas/$lineaId');

    return PedidoResumen.desdeJson(respuesta['data'] as Map<String, dynamic>);
  }

  /// Envía un borrador que se quedó sin mandar.
  Future<PedidoResumen> enviar(int pedidoId) async {
    final respuesta = await _api.post('pedidos/$pedidoId/enviar');

    return PedidoResumen.desdeJson(respuesta['data'] as Map<String, dynamic>);
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

/// Lo que el inicio pinta arriba: cuántos pedidos van y cuánto falta.
class ResumenPedidos {
  const ResumenPedidos({required this.enCurso, required this.porPagar, required this.ultimos});

  final int enCurso;
  final double porPagar;
  final List<PedidoResumen> ultimos;

  factory ResumenPedidos.desdeJson(Map<String, dynamic> json) {
    final datos = json['data'] as Map<String, dynamic>? ?? const {};

    return ResumenPedidos(
      enCurso: (datos['en_curso'] as num?)?.toInt() ?? 0,
      porPagar: (datos['por_pagar'] as num?)?.toDouble() ?? 0,
      ultimos: [
        for (final p in datos['ultimos'] as List<dynamic>? ?? const [])
          PedidoResumen.desdeJson(p as Map<String, dynamic>),
      ],
    );
  }
}
