import '../models/pagina.dart';
import '../models/vale.dart';
import 'api_service.dart';

class ValeService {
  ValeService({ApiService? api}) : _api = api ?? ApiService();

  final ApiService _api;

  /// Auditoría 21-oct-2026: la API ya pagina (100 por página). Los vales de
  /// una persona son pocos, así que se piden todas las páginas (con un tope
  /// de seguridad) y las pantallas siguen recibiendo la lista completa.
  Future<List<Vale>> listarValesVigentes() async {
    const maximoPaginas = 20;
    final vales = <Vale>[];
    var pagina = 1;

    while (true) {
      final respuesta = await _api.get('vales?page=$pagina&per_page=100');
      final actual = Pagina.desdeJson(respuesta, Vale.fromJson);
      vales.addAll(actual.elementos);

      if (!actual.hayMas || pagina >= maximoPaginas) break;
      pagina++;
    }

    return vales;
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