/// Una notificación de la bandeja (E16-01). Los nombres salen tal cual de
/// App\Http\Resources\NotificacionResource.
class Notificacion {
  const Notificacion({
    required this.id,
    required this.titulo,
    required this.mensaje,
    required this.leida,
    this.tipo,
    this.entidadTipo,
    this.entidadId,
    this.fecha,
  });

  final int id;
  final String? tipo;
  final String titulo;
  final String mensaje;
  final bool leida;

  /// A qué se refiere, para abrirlo al tocarla (por ejemplo 'pedido' y su id).
  final String? entidadTipo;
  final int? entidadId;

  final DateTime? fecha;

  /// Si al tocarla se puede abrir el detalle de un pedido propio.
  int? get pedidoId => entidadTipo == 'pedido' ? entidadId : null;

  Notificacion marcadaLeida() => Notificacion(
    id: id,
    tipo: tipo,
    titulo: titulo,
    mensaje: mensaje,
    leida: true,
    entidadTipo: entidadTipo,
    entidadId: entidadId,
    fecha: fecha,
  );

  factory Notificacion.desdeJson(Map<String, dynamic> json) {
    final creada = json['created_at'];

    return Notificacion(
      id: json['id'] as int,
      tipo: json['tipo'] as String?,
      titulo: json['titulo'] as String? ?? 'Aviso',
      mensaje: json['mensaje'] as String? ?? '',
      leida: json['leida'] as bool? ?? false,
      entidadTipo: json['entidad_tipo'] as String?,
      entidadId: json['entidad_id'] as int?,
      fecha: creada is String ? DateTime.tryParse(creada)?.toLocal() : null,
    );
  }
}
