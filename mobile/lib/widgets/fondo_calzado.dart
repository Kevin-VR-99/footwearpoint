import 'dart:ui' as ui;

import 'package:flutter/material.dart';

import '../tema/fp_colores.dart';

/// El fondo del login: siluetas de calzado, difuminadas (TG-281).
///
/// Antes era solo el degradado azul de la marca. Se pidió una imagen de
/// zapatería de fondo, y aquí se dibuja en vez de usar una fotografía: una
/// foto bajada de internet no se puede publicar en una app sin su licencia,
/// y de paso esto no pesa nada ni hay que esperar a que cargue.
///
/// Si algún día el equipo pone una foto propia en
/// `assets/brand/login-fondo.jpg`, se usa esa y el dibujo queda de respaldo
/// por si no se puede leer.
class FondoCalzado extends StatelessWidget {
  const FondoCalzado({super.key, required this.child});

  final Widget child;

  static const _foto = 'assets/brand/login-fondo.jpg';

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: const BoxDecoration(gradient: FpColores.degradadoMenu),
      child: Stack(
        fit: StackFit.expand,
        children: [
          // La foto, si la hay. Difuminada y oscurecida, para que el
          // formulario de encima se siga leyendo sin esfuerzo.
          Image.asset(
            _foto,
            fit: BoxFit.cover,
            opacity: const AlwaysStoppedAnimation(0.22),
            errorBuilder: (_, _, _) => const _SiluetasDifuminadas(),
          ),
          child,
        ],
      ),
    );
  }
}

/// El respaldo dibujado: zapatos repartidos, girados y muy difuminados.
class _SiluetasDifuminadas extends StatelessWidget {
  const _SiluetasDifuminadas();

  @override
  Widget build(BuildContext context) {
    return ImageFiltered(
      imageFilter: ui.ImageFilter.blur(sigmaX: 5, sigmaY: 5),
      child: CustomPaint(painter: _PintorDeZapatos(), size: Size.infinite),
    );
  }
}

class _PintorDeZapatos extends CustomPainter {
  /// Posición (de 0 a 1 del ancho y alto), tamaño y giro de cada zapato.
  /// Van a mano y no al azar: así el fondo se ve igual en cada arranque.
  static const _zapatos = <(double, double, double, double)>[
    (0.06, 0.07, 185, -0.28),
    (0.56, 0.14, 150, 0.40),
    (0.20, 0.27, 135, 0.16),
    (0.62, 0.40, 170, -0.20),
    (0.04, 0.52, 155, 0.33),
    (0.44, 0.63, 195, -0.12),
    (0.12, 0.79, 145, 0.24),
    (0.58, 0.90, 130, -0.34),
  ];

  @override
  void paint(Canvas lienzo, Size medida) {
    final pincel = Paint()..color = Colors.white.withValues(alpha: 0.085);

    for (final (x, y, largo, giro) in _zapatos) {
      lienzo.save();
      lienzo.translate(x * medida.width, y * medida.height);
      lienzo.rotate(giro);
      lienzo.drawPath(_zapato(largo), pincel);
      lienzo.restore();
    }
  }

  /// Un tenis de perfil, mirando a la derecha.
  ///
  /// Las medidas van en fracciones del largo, así que cambiar [largo] lo
  /// escala entero sin deformarlo. Lo que hace que se lea como calzado y no
  /// como una cuña es el hueco del cuello: sin esa muesca entre el talón y
  /// el empeine, la figura parece una rampa.
  Path _zapato(double largo) {
    final alto = largo * 0.46;
    final c = Path();

    // Suela, del talón a la punta, con la punta un poco levantada.
    c.moveTo(0, alto);
    c.lineTo(largo * 0.86, alto);
    c.quadraticBezierTo(largo * 0.99, alto * 0.99, largo, alto * 0.86);

    // Puntera, subiendo.
    c.quadraticBezierTo(largo * 0.99, alto * 0.70, largo * 0.88, alto * 0.62);

    // El empeine baja desde el cuello hasta la puntera.
    c.quadraticBezierTo(largo * 0.70, alto * 0.50, largo * 0.52, alto * 0.30);

    // El hueco del cuello: sube al borde delantero, baja dentro y vuelve a
    // subir por el talón. Esta muesca es la que dice "zapato".
    c.quadraticBezierTo(largo * 0.46, alto * 0.22, largo * 0.40, alto * 0.06);
    c.quadraticBezierTo(largo * 0.36, alto * 0.30, largo * 0.26, alto * 0.40);
    c.quadraticBezierTo(largo * 0.16, alto * 0.50, largo * 0.14, alto * 0.16);

    // Talón, bajando hasta la suela.
    c.quadraticBezierTo(largo * 0.12, alto * 0.02, largo * 0.05, alto * 0.06);
    c.quadraticBezierTo(0, alto * 0.14, 0, alto);
    c.close();

    return c;
  }

  @override
  bool shouldRepaint(_PintorDeZapatos oldDelegate) => false;
}
