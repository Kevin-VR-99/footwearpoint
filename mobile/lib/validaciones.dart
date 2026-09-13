/// Reglas de formulario que se repiten en varias pantallas, para no copiarlas
/// en cada una. Solo revisan lo mínimo antes de ir al servidor: las reglas
/// que mandan son las de los Form Request de Laravel.
class Validaciones {
  /// Revisa solo la forma: algo@algo.algo. La regla 'email' de Laravel es la
  /// que manda; esto evita un viaje al servidor por un error de dedo.
  static final _formatoCorreo = RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$');

  static String? correo(String? valor) {
    final correo = valor?.trim() ?? '';

    if (correo.isEmpty) return 'Escribe tu correo.';
    // Mismo texto que mandan LoginRequest y ForgotPasswordRequest.
    if (!_formatoCorreo.hasMatch(correo)) return 'El correo no es válido.';

    return null;
  }
}
