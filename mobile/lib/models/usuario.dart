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
  });

  final int id;
  final String nombre;
  final String email;
  final String? telefono;
  final String estado;

  factory Usuario.desdeJson(Map<String, dynamic> json) {
    return Usuario(
      id: json['id'] as int,
      nombre: json['nombre'] as String,
      email: json['email'] as String,
      telefono: json['telefono'] as String?,
      estado: json['estado'] as String,
    );
  }

  /// Lo contrario de [Usuario.desdeJson], con las mismas llaves: sirve para
  /// guardar el usuario en el teléfono y recuperarlo al volver a abrir la app.
  Map<String, dynamic> aJson() {
    return {
      'id': id,
      'nombre': nombre,
      'email': email,
      'telefono': telefono,
      'estado': estado,
    };
  }
}
