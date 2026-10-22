/// Una solicitud de cambio de producto con su línea de tiempo
/// (GET /api/solicitudes-cambio y /api/solicitudes-cambio/{id}).
class SolicitudCambio {
  const SolicitudCambio({
    required this.id,
    required this.estado,
    required this.estadoEtiqueta,
    this.productoNombre,
    this.talla,
    this.color,
    this.motivo,
    this.resolucion,
    this.pedidoId,
    this.fechaSolicitud,
    this.pasos = const [],
  });

  final int id;
  final String estado;
  final String estadoEtiqueta;
  final String? productoNombre;
  final String? talla;
  final String? color;
  final String? motivo;
  final String? resolucion;
  final int? pedidoId;
  final DateTime? fechaSolicitud;

  /// Solo viene en el detalle.
  final List<PasoCambio> pasos;

  /// Estados en los que ya no va a pasar nada más.
  bool get terminada => estado == 'rechazada' || estado == 'cerrada' || estado == 'vale_emitido';

  factory SolicitudCambio.desdeJson(Map<String, dynamic> json) {
    final producto = json['producto'];
    final timeline = json['timeline'];
    return SolicitudCambio(
      id: json['id'] as int,
      estado: json['estado'] as String? ?? 'solicitada',
      estadoEtiqueta: json['estado_etiqueta'] as String? ?? 'Solicitada',
      productoNombre: producto is Map<String, dynamic> ? producto['nombre'] as String? : null,
      talla: producto is Map<String, dynamic> ? producto['talla']?.toString() : null,
      color: producto is Map<String, dynamic> ? producto['color'] as String? : null,
      motivo: json['motivo'] as String?,
      resolucion: json['resolucion'] as String?,
      pedidoId: json['pedido_id'] as int?,
      fechaSolicitud: _fecha(json['fecha_solicitud']),
      pasos: [
        if (timeline is List)
          for (final p in timeline)
            if (p is Map<String, dynamic>) PasoCambio.desdeJson(p),
      ],
    );
  }
}

/// Un paso de la línea de tiempo: estado, cuándo, quién y la nota.
class PasoCambio {
  const PasoCambio({
    required this.estado,
    required this.etiqueta,
    this.estadoAnterior,
    this.nota,
    this.fecha,
    this.fechaCdmxServidor,
    this.quien,
  });

  final String estado;
  final String etiqueta;
  final String? estadoAnterior;
  final String? nota;
  final DateTime? fecha;

  /// "dd/mm/aaaa hh:mm" en hora de la Ciudad de México, como lo manda el servidor.
  final String? fechaCdmxServidor;
  final String? quien;

  /// Fecha y hora en la Ciudad de México. Si el servidor no la mandó, se
  /// calcula (CDMX está en UTC-6 todo el año desde 2022: sin horario de verano).
  String get fechaCdmx {
    if (fechaCdmxServidor != null && fechaCdmxServidor!.isNotEmpty) return fechaCdmxServidor!;
    return formatearCdmx(fecha) ?? '';
  }

  factory PasoCambio.desdeJson(Map<String, dynamic> json) => PasoCambio(
        estado: json['estado'] as String? ?? '',
        etiqueta: json['etiqueta'] as String? ?? '',
        estadoAnterior: json['estado_anterior'] as String?,
        nota: json['nota'] as String?,
        fecha: _fecha(json['fecha']),
        fechaCdmxServidor: json['fecha_cdmx'] as String?,
        quien: json['quien'] as String?,
      );
}

DateTime? _fecha(Object? valor) => valor is String ? DateTime.tryParse(valor) : null;

/// dd/mm/aaaa hh:mm en la Ciudad de México (UTC-6, sin horario de verano).
String? formatearCdmx(DateTime? fecha) {
  if (fecha == null) return null;
  final f = fecha.toUtc().subtract(const Duration(hours: 6));
  String dos(int n) => n.toString().padLeft(2, '0');
  return '${dos(f.day)}/${dos(f.month)}/${f.year} ${dos(f.hour)}:${dos(f.minute)}';
}
