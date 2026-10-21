/// Una página de un listado de la API (auditoría 21-oct-2026).
///
/// El servidor regresa la lista en "data" y, desde la paginación, también
/// "meta" con current_page y last_page. Si "meta" no viene (servidor viejo),
/// se toma como una sola página.
class Pagina<T> {
  const Pagina({required this.elementos, required this.paginaActual, required this.ultimaPagina});

  final List<T> elementos;
  final int paginaActual;
  final int ultimaPagina;

  bool get hayMas => paginaActual < ultimaPagina;

  factory Pagina.desdeJson(Map<String, dynamic> json, T Function(Map<String, dynamic>) convertir) {
    final meta = json['meta'];
    final actual = meta is Map<String, dynamic> ? (meta['current_page'] as num?)?.toInt() ?? 1 : 1;
    final ultima = meta is Map<String, dynamic> ? (meta['last_page'] as num?)?.toInt() ?? actual : actual;

    return Pagina(
      elementos: [
        for (final e in json['data'] as List<dynamic>? ?? const []) convertir(e as Map<String, dynamic>),
      ],
      paginaActual: actual,
      ultimaPagina: ultima,
    );
  }
}
