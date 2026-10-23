/// El usuario que inició sesión.
///
/// Los nombres de los campos salen tal cual de lo que responde Laravel en
/// App\Http\Controllers\Api\AuthController::login — no se inventó ninguno.
class Usuario {
  const Usuario({
    required this.id,
    required this.nombre,
    required this.email,
    required this.estado,
    this.telefono,
    this.fotoUrl,
    this.cambiosPendientes = const {},
  });

  final int id;
  final String nombre;
  final String email;
  final String? telefono;
  final String estado;

  /// Foto de perfil (URL pública). Null si no ha subido ninguna.
  final String? fotoUrl;

  /// Cambios de correo o teléfono que esperan confirmación por correo:
  /// {'email': 'nuevo@...', 'telefono': '961...'}.
  final Map<String, String> cambiosPendientes;

  String? get correoPendiente => cambiosPendientes['email'];
  String? get telefonoPendiente => cambiosPendientes['telefono'];

  /// Lo contrario de [desdeJson]. TG-281: hace falta para poder guardar la
  /// sesion en el telefono y volver a armarla sin internet.
  Map<String, dynamic> aJson() => {
    'id': id,
    'nombre': nombre,
    'email': email,
    'telefono': telefono,
    'estado': estado,
    'foto_url': fotoUrl,
    'cambios_pendientes': cambiosPendientes,
  };

  factory Usuario.desdeJson(Map<String, dynamic> json) {
    // Laravel manda {} con cambios y, en versiones viejas, nada o [].
    final crudos = json['cambios_pendientes'];
    final pendientes = <String, String>{
      if (crudos is Map)
        for (final e in crudos.entries)
          if (e.value is String) e.key.toString(): e.value as String,
    };

    return Usuario(
      id: json['id'] as int,
      nombre: json['nombre'] as String,
      email: json['email'] as String,
      telefono: json['telefono'] as String?,
      estado: json['estado'] as String,
      fotoUrl: json['foto_url'] as String?,
      cambiosPendientes: pendientes,
    );
  }
}
