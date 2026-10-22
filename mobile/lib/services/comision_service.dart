import 'api_service.dart';

/// Comisión y meta del mes del cliente mayorista (GET /api/revendedor/comisiones).
class ResumenComision {
  const ResumenComision({
    required this.periodo,
    required this.porcentaje,
    required this.pedidosPagados,
    required this.ventaPagada,
    required this.comision,
    this.meta,
    this.avance,
    this.lugar,
  });

  final String periodo;
  final double porcentaje;
  final int pedidosPagados;
  final double ventaPagada;
  final double comision;
  final double? meta;
  final double? avance;
  final int? lugar;

  static double _numero(dynamic v) => v is num ? v.toDouble() : double.tryParse('$v') ?? 0;

  factory ResumenComision.fromJson(Map<String, dynamic> json) {
    return ResumenComision(
      periodo: '${json['periodo'] ?? ''}',
      porcentaje: _numero(json['porcentaje']),
      pedidosPagados: (json['pedidos_pagados'] as num?)?.toInt() ?? 0,
      ventaPagada: _numero(json['venta_pagada']),
      comision: _numero(json['comision']),
      meta: json['meta'] == null ? null : _numero(json['meta']),
      avance: json['avance'] == null ? null : _numero(json['avance']),
      lugar: (json['lugar'] as num?)?.toInt(),
    );
  }
}

class ComisionService {
  ComisionService({ApiService? api}) : _api = api ?? ApiService();

  final ApiService _api;

  Future<ResumenComision> delMes({String? periodo}) async {
    final ruta = periodo == null ? 'revendedor/comisiones' : 'revendedor/comisiones?periodo=$periodo';
    final respuesta = await _api.get(ruta);
    final datos = respuesta['data'];
    return ResumenComision.fromJson(datos is Map<String, dynamic> ? datos : <String, dynamic>{});
  }
}
