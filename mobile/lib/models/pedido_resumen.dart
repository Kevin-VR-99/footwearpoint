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
    this.fechaEntrega,
    this.fechaLimiteRecoleccion,
    this.lineas = const [],
    this.pagos = const [],
    this.puedePagarAnticipoMercadoPago = false,
    this.puedePagarSaldoMercadoPago = false,
    this.puedePagarTotalMercadoPago = false,
    this.pagoMercadoPagoPendiente = false,
    this.pagoMercadoPagoPendienteTipo,
    this.compartirTexto,
  });

  /// Resumen del pedido listo para mandarlo por WhatsApp (solo en el detalle).
  final String? compartirTexto;

  final int id;
  final String folio;
  final String estado;

  /// 'cliente_directo' o 'revendedor'.
  final String? tipo;

  /// Cuándo se envió (fecha_colocacion); si no hay, cuándo se creó.
  final DateTime? fecha;

  /// TG-254: cuándo se entregó; desde ahí corre el plazo para pedir cambio.
  final DateTime? fechaEntrega;

  /// TG-125: hasta cuándo puede recogerlo, cuando ya está listo.
  final DateTime? fechaLimiteRecoleccion;

  /// «Recoge antes del 15/10/2026» mientras esté listo para entrega.
  String? get avisoRecoleccion {
    final limite = fechaLimiteRecoleccion;
    if (estado != 'listo_entrega' || limite == null) return null;
    return 'Recoge antes del ${formatoFecha(limite, conHora: false)}';
  }

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

  /// TG-268: qué puede pagar ya con Mercado Pago (lo decide el servidor).
  final bool puedePagarAnticipoMercadoPago;
  final bool puedePagarSaldoMercadoPago;
  final bool puedePagarTotalMercadoPago;

  /// Hay un pago con Mercado Pago esperando confirmación (solo en el detalle).
  final bool pagoMercadoPagoPendiente;

  /// 'anticipo', 'saldo_pedido' o 'total_revendedor'.
  final String? pagoMercadoPagoPendienteTipo;

  bool get esRevendedor => tipo == 'revendedor';

  /// Lo que se puede pagar ahora con Mercado Pago, o null si nada.
  TipoPagoMercadoPago? get pagoMercadoPagoDisponible {
    if (puedePagarAnticipoMercadoPago) return TipoPagoMercadoPago.anticipo;
    if (puedePagarTotalMercadoPago) return TipoPagoMercadoPago.total;
    if (puedePagarSaldoMercadoPago) return TipoPagoMercadoPago.saldo;
    return null;
  }

  /// El pago con Mercado Pago que falta confirmar, o null si no hay.
  TipoPagoMercadoPago? get pagoMercadoPagoPorVerificar =>
      pagoMercadoPagoPendiente ? TipoPagoMercadoPago.desdeTipoPago(pagoMercadoPagoPendienteTipo) : null;

  /// Cuánto se cobraría con Mercado Pago por ese concepto.
  double montoMercadoPago(TipoPagoMercadoPago tipoPago) => tipoPago == TipoPagoMercadoPago.anticipo
      ? (anticipoPendiente < saldo ? anticipoPendiente : saldo)
      : saldo;

  factory PedidoResumen.desdeJson(Map<String, dynamic> json) {
    double numero(String llave) => (json[llave] as num?)?.toDouble() ?? 0;

    return PedidoResumen(
      id: json['id'] as int,
      folio: json['folio'] as String? ?? '#${json['id']}',
      estado: json['estado'] as String? ?? '',
      tipo: json['tipo'] as String?,
      fecha: _fecha(json['fecha_colocacion']) ?? _fecha(json['created_at']),
      fechaEntrega: _fecha(json['fecha_entrega']),
      fechaLimiteRecoleccion: _fecha(json['fecha_limite_recoleccion']),
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
      puedePagarAnticipoMercadoPago: json['puede_pagar_anticipo_mercado_pago'] == true,
      puedePagarSaldoMercadoPago: json['puede_pagar_saldo_mercado_pago'] == true,
      puedePagarTotalMercadoPago: json['puede_pagar_total_mercado_pago'] == true,
      pagoMercadoPagoPendiente: json['pago_mercado_pago_pendiente'] == true,
      pagoMercadoPagoPendienteTipo: json['pago_mercado_pago_pendiente_tipo'] as String?,
      compartirTexto: json['compartir_texto'] as String?,
    );
  }
}

/// TG-268: los tres pagos con Mercado Pago que acepta el servidor. [ruta] es
/// el tramo de la URL: /pedidos/{id}/{ruta}/mercado-pago.
enum TipoPagoMercadoPago {
  anticipo('anticipo', 'Anticipo', 'Pagar anticipo con tarjeta'),
  saldo('saldo', 'Saldo del pedido', 'Pagar saldo con tarjeta'),
  total('total', 'Total del pedido', 'Pagar con tarjeta');

  const TipoPagoMercadoPago(this.ruta, this.concepto, this.textoBoton);

  final String ruta;
  final String concepto;
  final String textoBoton;

  /// Desde pagos.tipo del servidor.
  static TipoPagoMercadoPago? desdeTipoPago(String? tipo) => switch (tipo) {
    'anticipo' => TipoPagoMercadoPago.anticipo,
    'saldo_pedido' => TipoPagoMercadoPago.saldo,
    'total_revendedor' => TipoPagoMercadoPago.total,
    _ => null,
  };
}

class LineaPedido {
  const LineaPedido({
    this.id,
    required this.productoNombre,
    required this.modelo,
    required this.talla,
    required this.color,
    required this.cantidad,
    required this.precioUnitario,
    required this.subtotal,
    this.cambio,
    this.bajoPedido = false,
    this.estadoSurtido = '',
    this.motivoNoSurtido,
    this.resolucionNoSurtido,
    this.imagen,
  });

  /// pedido_detalle.id; hace falta para pedir un cambio (TG-254).
  final int? id;

  final String productoNombre;
  final String modelo;
  final String talla;
  final String color;
  final int cantidad;
  final double precioUnitario;
  final double subtotal;

  /// TG-254: si se puede pedir cambio y hasta cuándo. Null si el servidor no
  /// lo manda (versiones anteriores o la lista de pedidos).
  final CambioLinea? cambio;

  /// TG-266: la talla está bajo pedido hoy (tarda más en llegar).
  final bool bajoPedido;

  /// TG-241 (A12): 'no_surtido' cuando la fábrica confirmó este producto y al
  /// final no lo mandó.
  final String estadoSurtido;

  /// Por qué no se surtió, tal cual lo escribió la distribuidora.
  final String? motivoNoSurtido;

  /// 'pendiente' mientras la persona no haya dicho qué prefiere; después
  /// 'sustitucion' o 'reembolso_anticipo'.
  final String? resolucionNoSurtido;

  /// Miniatura del calzado. Null si esa temporada todavía no tiene foto.
  final String? imagen;

  /// True si la fábrica no lo surtió y todavía falta que la persona elija.
  bool get esperaDecision => estadoSurtido == 'no_surtido' && resolucionNoSurtido == 'pendiente';

  bool get noSurtido => estadoSurtido == 'no_surtido';

  factory LineaPedido.desdeJson(Map<String, dynamic> json) {
    final cambio = json['cambio'];
    return LineaPedido(
      id: json['id'] as int?,
      cambio: cambio is Map<String, dynamic> ? CambioLinea.desdeJson(cambio) : null,
      productoNombre: json['producto_nombre'] as String? ?? '',
      modelo: json['modelo'] as String? ?? '',
      talla: json['talla']?.toString() ?? '',
      color: json['color'] as String? ?? '',
      cantidad: json['cantidad'] as int? ?? 0,
      precioUnitario: (json['precio_unitario'] as num?)?.toDouble() ?? 0,
      subtotal: (json['subtotal'] as num?)?.toDouble() ?? 0,
      bajoPedido: json['bajo_pedido'] == true,
      estadoSurtido: json['estado_surtido'] as String? ?? '',
      motivoNoSurtido: json['motivo_no_surtido'] as String?,
      resolucionNoSurtido: json['resolucion_no_surtido'] as String?,
      imagen: json['imagen'] as String?,
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
    this.estado = '',
    this.fecha,
  });

  /// 'aplicado', 'pendiente'... Un pago pendiente todavía no cuenta.
  final String estado;

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
      estado: json['estado'] as String? ?? '',
      fecha: _fecha(json['fecha_pago']),
    );
  }

  bool get esPendiente => estado == 'pendiente';

  /// "mercado_pago" -> "Mercado Pago"; "efectivo" -> "Efectivo".
  String get metodoParaMostrar => switch (metodo) {
    'mercado_pago' => 'Mercado Pago',
    '' => '',
    _ => '${metodo[0].toUpperCase()}${metodo.substring(1).replaceAll('_', ' ')}',
  };

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


/// TG-254 (E12-01): el cambio de producto de una línea, tal como lo decide el
/// servidor (plazo configurable por distribuidora). La app no calcula plazos.
class CambioLinea {
  const CambioLinea({
    required this.puedeSolicitar,
    this.fechaLimite,
    this.solicitudId,
    this.solicitudEstado,
  });

  final bool puedeSolicitar;

  /// Último día para pedirlo, tal como lo manda el servidor (su zona horaria).
  final DateTime? fechaLimite;

  final int? solicitudId;

  /// 'solicitada', 'autorizada', 'rechazada', ...
  final String? solicitudEstado;

  bool get tieneSolicitud => solicitudId != null;

  /// dd/mm/aaaa del último día, sin pasarlo a la hora del teléfono: el plazo
  /// es por día de la distribuidora.
  String? get fechaLimiteTexto {
    final f = fechaLimite;
    if (f == null) return null;
    String dos(int n) => n.toString().padLeft(2, '0');
    return '${dos(f.day)}/${dos(f.month)}/${f.year}';
  }

  String get estadoParaMostrar => switch (solicitudEstado) {
    'solicitada' => 'Cambio solicitado',
    'autorizada' => 'Cambio autorizado',
    'rechazada' => 'Cambio rechazado',
    'enviada_fabrica' => 'Cambio en revisión con fábrica',
    'aceptada_fabrica' => 'Cambio aceptado por fábrica',
    'vale_emitido' => 'Vale emitido',
    'cerrada' => 'Cambio cerrado',
    _ => 'Cambio solicitado',
  };

  factory CambioLinea.desdeJson(Map<String, dynamic> json) {
    final solicitud = json['solicitud'];
    final limite = json['fecha_limite'];
    return CambioLinea(
      puedeSolicitar: json['puede_solicitar'] == true,
      // Solo la parte de la fecha: es el día límite en la zona del servidor.
      fechaLimite: limite is String && limite.length >= 10 ? DateTime.tryParse(limite.substring(0, 10)) : null,
      solicitudId: solicitud is Map<String, dynamic> ? solicitud['id'] as int? : null,
      solicitudEstado: solicitud is Map<String, dynamic> ? solicitud['estado'] as String? : null,
    );
  }
}
