/// Un pedido como lo ve la app (TG-165): al enviarlo, en "Mis pedidos" y en
/// su detalle.
///
/// Los nombres salen tal cual de App\Http\Resources\PedidoResource. Los
/// importes los calcula SIEMPRE el servidor (precio, anticipo, saldo): la app
/// no los decide, solo los muestra.
class PedidoResumen {
  const PedidoResumen({
    required this.id,
    required this.folio,
    required this.estado,
    required this.total,
    required this.pagado,
    required this.pagadoConVales,
    required this.saldo,
    required this.anticipoRequerido,
    required this.anticipoPendiente,
    this.tipo,
    this.fecha,
    this.lineas = const [],
    this.pagos = const [],
  });

  final int id;
  final String folio;
  final String estado;

  /// 'cliente_directo' o 'revendedor'.
  final String? tipo;

  /// Cuándo se envió (fecha_colocacion); si no hay, cuándo se creó.
  final DateTime? fecha;

  final double total;

  /// Todo lo pagado, incluido lo aplicado con vales (TG-167).
  final double pagado;

  /// La parte de [pagado] que se cubrió con vales.
  final double pagadoConVales;

  /// Lo que falta pagar del pedido completo.
  final double saldo;

  /// Anticipo total del pedido (anticipo_por_producto × piezas, en el servidor).
  final double anticipoRequerido;

  /// Lo que falta del anticipo: es lo que se paga en mostrador (E8-02).
  final double anticipoPendiente;

  /// Solo vienen en el detalle (GET /pedidos/{id}); en la lista van vacías.
  final List<LineaPedido> lineas;
  final List<PagoPedido> pagos;

  bool get esRevendedor => tipo == 'revendedor';

  factory PedidoResumen.desdeJson(Map<String, dynamic> json) {
    double numero(String llave) => (json[llave] as num?)?.toDouble() ?? 0;

    return PedidoResumen(
      id: json['id'] as int,
      folio: json['folio'] as String? ?? '#${json['id']}',
      estado: json['estado'] as String? ?? '',
      tipo: json['tipo'] as String?,
      fecha: _fecha(json['fecha_colocacion']) ?? _fecha(json['created_at']),
      total: numero('total'),
      pagado: numero('pagado'),
      pagadoConVales: numero('pagado_con_vales'),
      saldo: numero('saldo'),
      anticipoRequerido: numero('anticipo_requerido'),
      anticipoPendiente: numero('anticipo_pendiente'),
      lineas: [
        for (final l in json['lineas'] as List<dynamic>? ?? const [])
          LineaPedido.desdeJson(l as Map<String, dynamic>),
      ],
      pagos: [
        for (final p in json['pagos'] as List<dynamic>? ?? const [])
          PagoPedido.desdeJson(p as Map<String, dynamic>),
      ],
    );
  }
}

class LineaPedido {
  const LineaPedido({
    required this.productoNombre,
    required this.modelo,
    required this.talla,
    required this.color,
    required this.cantidad,
    required this.precioUnitario,
    required this.subtotal,
  });

  final String productoNombre;
  final String modelo;
  final String talla;
  final String color;
  final int cantidad;
  final double precioUnitario;
  final double subtotal;

  factory LineaPedido.desdeJson(Map<String, dynamic> json) {
    return LineaPedido(
      productoNombre: json['producto_nombre'] as String? ?? '',
      modelo: json['modelo'] as String? ?? '',
      talla: json['talla']?.toString() ?? '',
      color: json['color'] as String? ?? '',
      cantidad: json['cantidad'] as int? ?? 0,
      precioUnitario: (json['precio_unitario'] as num?)?.toDouble() ?? 0,
      subtotal: (json['subtotal'] as num?)?.toDouble() ?? 0,
    );
  }
}

/// Un pago registrado en mostrador (anticipo, saldo...). Los vales no vienen
/// aquí: se suman aparte en [PedidoResumen.pagadoConVales].
class PagoPedido {
  const PagoPedido({
    required this.folio,
    required this.tipo,
    required this.metodo,
    required this.monto,
    this.fecha,
  });

  final String folio;
  final String tipo;
  final String metodo;
  final double monto;
  final DateTime? fecha;

  factory PagoPedido.desdeJson(Map<String, dynamic> json) {
    return PagoPedido(
      folio: json['folio'] as String? ?? '',
      tipo: json['tipo'] as String? ?? '',
      metodo: json['metodo'] as String? ?? '',
      monto: (json['monto'] as num?)?.toDouble() ?? 0,
      fecha: _fecha(json['fecha_pago']),
    );
  }

  /// "saldo_pedido" -> "Saldo pedido".
  String get tipoParaMostrar =>
      tipo.isEmpty ? '' : '${tipo[0].toUpperCase()}${tipo.substring(1).replaceAll('_', ' ')}';
}

DateTime? _fecha(Object? valor) => valor is String ? DateTime.tryParse(valor)?.toLocal() : null;

/// 2026-09-18 14:05 -> "18/09/2026 14:05"
String formatoFecha(DateTime fecha, {bool conHora = true}) {
  String dos(int n) => n.toString().padLeft(2, '0');
  final dia = '${dos(fecha.day)}/${dos(fecha.month)}/${fecha.year}';
  return conHora ? '$dia ${dos(fecha.hour)}:${dos(fecha.minute)}' : dia;
}
