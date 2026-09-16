class ClientePrivadoRevendedor {
  final int id;
  final int revendedorId;
  final String nombre;
  final String? telefono;
  final String? producto;
  final double? monto;
  final double? saldo;
  final String? referencia;
  final String? notas;

  ClientePrivadoRevendedor({
    required this.id,
    required this.revendedorId,
    required this.nombre,
    this.telefono,
    this.producto,
    this.monto,
    this.saldo,
    this.referencia,
    this.notas,
  });

  factory ClientePrivadoRevendedor.fromJson(Map<String, dynamic> json) {
    return ClientePrivadoRevendedor(
      id: json['id'] is int ? json['id'] : int.parse(json['id'].toString()),
      revendedorId: json['revendedor_id'] is int ? json['revendedor_id'] : int.parse(json['revendedor_id'].toString()),
      nombre: json['nombre'] ?? '',
      telefono: json['telefono'],
      producto: json['producto'],
      monto: json['monto'] != null ? double.parse(json['monto'].toString()) : null,
      saldo: json['saldo'] != null ? double.parse(json['saldo'].toString()) : null,
      referencia: json['referencia'],
      notas: json['notas'],
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'nombre': nombre,
      'telefono': telefono,
      'producto': producto,
      'monto': monto,
      'saldo': saldo,
      'referencia': referencia,
      'notas': notas,
    };
  }
}