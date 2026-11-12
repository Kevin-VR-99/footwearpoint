/// TG-323 (K24): un documento legal que falta aceptar, como lo manda
/// /api/auth/me en `legales_pendientes`.
class DocumentoLegal {
  const DocumentoLegal({
    required this.tipo,
    required this.nombre,
    this.version,
    this.fecha,
    this.url,
  });

  /// `aviso_privacidad` o `terminos_condiciones`.
  final String tipo;
  final String nombre;
  final String? version;
  final String? fecha;

  /// La página pública donde se lee completo.
  final String? url;

  factory DocumentoLegal.desdeJson(Map<String, dynamic> json) => DocumentoLegal(
    tipo: json['tipo_documento'] as String,
    nombre: json['nombre'] as String? ?? json['tipo_documento'] as String,
    version: json['version'] as String?,
    fecha: json['fecha_actualizacion'] as String?,
    url: json['url'] as String?,
  );

  Map<String, dynamic> aJson() => {
    'tipo_documento': tipo,
    'nombre': nombre,
    'version': version,
    'fecha_actualizacion': fecha,
    'url': url,
  };

  /// Si el servidor no dice cuáles faltan (una versión vieja del servidor),
  /// se piden los dos, sin versión: la versión la pone el servidor.
  static const List<DocumentoLegal> ambos = [
    DocumentoLegal(tipo: 'aviso_privacidad', nombre: 'Aviso de privacidad'),
    DocumentoLegal(tipo: 'terminos_condiciones', nombre: 'Términos y condiciones'),
  ];
}
