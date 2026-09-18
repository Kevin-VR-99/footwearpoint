/// Lo que la app necesita de un pedido después de enviarlo (TG-165).
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
  });

  final int id;
  final String folio;
  final String estado;
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

  factory PedidoResumen.desdeJson(Map<String, dynamic> json) {
    double numero(String llave) => (json[llave] as num?)?.toDouble() ?? 0;

    return PedidoResumen(
      id: json['id'] as int,
      folio: json['folio'] as String? ?? '#${json['id']}',
      estado: json['estado'] as String? ?? '',
      total: numero('total'),
      pagado: numero('pagado'),
      pagadoConVales: numero('pagado_con_vales'),
      saldo: numero('saldo'),
      anticipoRequerido: numero('anticipo_requerido'),
      anticipoPendiente: numero('anticipo_pendiente'),
    );
  }
}
