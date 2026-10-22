/// Guarda un resultado unos minutos para no volver a pedirlo.
class CacheMinutos<T> {
  CacheMinutos({this.vigencia = const Duration(minutes: 10)});

  final Duration vigencia;
  T? valor;
  String? etag;
  DateTime? en;

  bool get vigente => valor != null && en != null && DateTime.now().difference(en!) < vigencia;

  void guardar(T nuevo, {String? etag}) {
    valor = nuevo;
    if (etag != null && etag.isNotEmpty) this.etag = etag;
    en = DateTime.now();
  }

  /// El servidor dijo que no cambió: se alarga la vigencia y se conserva el dato.
  void confirmar() {
    en = DateTime.now();
  }

  void olvidar() {
    valor = null;
    etag = null;
    en = null;
  }
}
