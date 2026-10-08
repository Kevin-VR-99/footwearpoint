class Vale {
  final int id;
  final String folio;
  final double montoOriginal;
  final double saldoActual;
  final DateTime? fechaVencimiento;
  final String estado;

  Vale({
    required this.id,
    required this.folio,
    required this.montoOriginal,
    required this.saldoActual,
    this.fechaVencimiento,
    required this.estado,
  });

  factory Vale.fromJson(Map<String, dynamic> json) {
    return Vale(
      id: json['id'] is int ? json['id'] : int.parse(json['id'].toString()),
      folio: json['folio'] ?? '',
      montoOriginal: json['monto_original'] != null ? double.parse(json['monto_original'].toString()) : 0.0,
      saldoActual: json['saldo_actual'] != null ? double.parse(json['saldo_actual'].toString()) : 0.0,
      fechaVencimiento: json['fecha_vencimiento'] != null ? DateTime.parse(json['fecha_vencimiento']) : null,
      estado: json['estado'] ?? 'activo',
    );
  }
}

class ResultadoAplicarVale {
  final String folio;
  final double saldoAnterior;
  final double montoAplicado;
  final double nuevoSaldoVale;
  final double restanteAPagar;

  ResultadoAplicarVale({
    required this.folio,
    required this.saldoAnterior,
    required this.montoAplicado,
    required this.nuevoSaldoVale,
    required this.restanteAPagar,
  });

  factory ResultadoAplicarVale.fromJson(Map<String, dynamic> json) {
    return ResultadoAplicarVale(
      folio: json['folio'] ?? '',
      saldoAnterior: double.parse(json['saldo_anterior'].toString()),
      montoAplicado: double.parse(json['monto_aplicado'].toString()),
      nuevoSaldoVale: double.parse(json['nuevo_saldo_vale'].toString()),
      restanteAPagar: double.parse(json['restante_a_pagar'].toString()),
    );
  }
}