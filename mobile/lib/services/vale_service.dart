import '../models/vale.dart';
import 'api_service.dart';

class ValeService {
  ValeService({ApiService? api}) : _api = api ?? ApiService();

  final ApiService _api;

  Future<List<Vale>> listarValesVigentes() async {
    final respuesta = await _api.get('vales');
    final lista = respuesta['data'] as List? ?? [];
    return lista.map((e) => Vale.fromJson(e as Map<String, dynamic>)).toList();
  }

  Future<void> aplicarVale({
    required int valeId,
    required double monto,
    int? pedidoId,
  }) async {
    final Map<String, dynamic> cuerpo = {
      'monto': monto,
    };

    if (pedidoId != null) {
      cuerpo['pedido_id'] = pedidoId;
    }

    await _api.post(
      'vales/$valeId/aplicar',
      cuerpo: cuerpo,
    );
  }
}