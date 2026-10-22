import '../models/notificacion.dart';
import '../models/pagina.dart';
import 'api_service.dart';
import 'cache_minutos.dart';

/// La bandeja de notificaciones (E16-01; TG-165). El servidor solo regresa y
/// solo deja marcar las del usuario que inició sesión.
class NotificacionService {
  NotificacionService(this._api);

  final ApiService _api;

  static final _cache = CacheMinutos<Pagina<Notificacion>>();
  static final _conteo = CacheMinutos<int>();

  static void olvidarCache() {
    _cache.olvidar();
    _conteo.olvidar();
  }

  /// La primera página se guarda unos minutos. [forzar] vuelve a preguntar
  /// y, si nada cambió, el servidor responde 304.
  Future<Pagina<Notificacion>> listarPagina({int pagina = 1, int porPagina = 25, bool forzar = false}) async {
    final esPrimera = pagina == 1 && porPagina == 25;
    if (esPrimera && !forzar && _cache.vigente) return _cache.valor!;

    final respuesta = await _api.getConEtag(
      'notificaciones?page=$pagina&per_page=$porPagina',
      etag: esPrimera ? _cache.etag : null,
    );
    if (respuesta.sinCambios && esPrimera && _cache.valor != null) {
      _cache.confirmar();
      return _cache.valor!;
    }

    final lista = Pagina.desdeJson(respuesta.cuerpo, Notificacion.desdeJson);
    if (esPrimera) _cache.guardar(lista, etag: respuesta.etag);
    final noLeidas = respuesta.cuerpo['no_leidas'];
    if (noLeidas is num) _conteo.guardar(noLeidas.toInt());

    return lista;
  }

  /// Para el globito de la campana. Usa el total del servidor, no el largo
  /// de una página.
  Future<int> contarNoLeidas({bool forzar = false}) async {
    if (!forzar && _conteo.vigente) return _conteo.valor!;

    final respuesta = await _api.getConEtag(
      'notificaciones?solo_no_leidas=1&per_page=1',
      etag: _conteo.etag,
    );
    if (respuesta.sinCambios && _conteo.valor != null) {
      _conteo.confirmar();
      return _conteo.valor!;
    }

    final noLeidas = respuesta.cuerpo['no_leidas'];
    final total = noLeidas is num
        ? noLeidas.toInt()
        : (respuesta.cuerpo['data'] is List ? (respuesta.cuerpo['data'] as List).length : 0);
    _conteo.guardar(total, etag: respuesta.etag);

    return total;
  }

  Future<void> marcarLeida(int id) async {
    await _api.post('notificaciones/$id/marcar-leida');
    olvidarCache();
  }

  Future<void> marcarTodasLeidas() async {
    await _api.post('notificaciones/marcar-todas-leidas');
    olvidarCache();
  }

  /// TG-281: quitar un aviso de la bandeja. El servidor solo deja borrar los
  /// de quien inició sesión.
  Future<void> borrar(int id) async {
    await _api.delete('notificaciones/$id');
    olvidarCache();
  }

  Future<void> borrarTodas() async {
    await _api.delete('notificaciones');
    olvidarCache();
  }
}
