import '../models/producto_catalogo.dart';
import 'api_service.dart';

/// Consulta el catálogo de la distribuidora del usuario (E4-05).
///
/// La app no manda distribuidora ni filtros: el servidor ya regresa solo los
/// productos publicados de campañas activas de SU distribuidora, y decide si
/// incluye el precio mayorista según el rol.
///
/// Queda separado de las pantallas para que el flujo de pedidos (E8-01, E9)
/// pueda reutilizarlo.
class CatalogoService {
  const CatalogoService(this._api);

  final ApiService _api;

  Future<List<ProductoCatalogo>> obtener() async {
    final respuesta = await _api.get('catalogo');

    return (respuesta['data'] as List<dynamic>)
        .map((p) => ProductoCatalogo.desdeJson(p as Map<String, dynamic>))
        .toList();
  }
}
