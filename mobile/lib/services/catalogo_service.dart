import '../models/producto_catalogo.dart';
import 'api_service.dart';
import 'cache_minutos.dart';

/// Consulta el catálogo de la distribuidora del usuario (E4-05).
///
/// La app no manda distribuidora ni filtros: el servidor ya regresa solo los
/// productos publicados de campañas activas de SU distribuidora, y decide si
/// incluye el precio mayorista según el rol.
///
/// Se guarda unos minutos. Al volver a pedirlo, si no cambió el servidor
/// responde 304 y no se vuelve a bajar.
class CatalogoService {
  CatalogoService(this._api);

  final ApiService _api;

  static final _cache = CacheMinutos<List<ProductoCatalogo>>();

  static void olvidarCache() => _cache.olvidar();

  bool get vigente => _cache.vigente;

  List<ProductoCatalogo>? get enMemoria => _cache.valor;

  Future<List<ProductoCatalogo>> obtener({bool forzar = false}) async {
    if (!forzar && _cache.vigente) return List.unmodifiable(_cache.valor!);

    final respuesta = await _api.getConEtag('catalogo', etag: _cache.etag);
    if (respuesta.sinCambios && _cache.valor != null) {
      _cache.confirmar();
      return List.unmodifiable(_cache.valor!);
    }

    final lista = (respuesta.cuerpo['data'] as List<dynamic>)
        .map((p) => ProductoCatalogo.desdeJson(p as Map<String, dynamic>))
        .toList();
    _cache.guardar(lista, etag: respuesta.etag);

    return List.unmodifiable(lista);
  }
}
