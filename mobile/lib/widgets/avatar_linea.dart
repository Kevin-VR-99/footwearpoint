import 'package:flutter/material.dart';

import '../tema/fp_colores.dart';

/// TG-386 A26 — El logo de la línea o, si no tiene, su inicial.
///
/// Es el mismo criterio que x-ui.avatar-linea en la web: caja redondeada y
/// BoxFit.contain, porque un logotipo recortado al centro pierde el nombre de
/// la marca. Una sola letra: "Impuls" y "Cklass" son una palabra. La inicial
/// no se lee en voz alta porque el nombre de la línea va al lado.
class FpAvatarLinea extends StatelessWidget {
  const FpAvatarLinea({super.key, required this.nombre, this.logotipoUrl, this.tamano = 40});

  final String nombre;
  final String? logotipoUrl;
  final double tamano;

  static String inicial(String nombre) {
    final limpio = nombre.trim();
    if (limpio.isEmpty) return '?';
    return String.fromCharCode(limpio.runes.first).toUpperCase();
  }

  @override
  Widget build(BuildContext context) {
    final url = logotipoUrl?.trim() ?? '';
    final letra = ExcludeSemantics(
      child: Center(
        child: Text(
          inicial(nombre),
          style: TextStyle(
            color: FpColores.primario,
            fontWeight: FontWeight.w600,
            fontSize: tamano * 0.4,
          ),
        ),
      ),
    );

    return Container(
      width: tamano,
      height: tamano,
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        color: url.isEmpty ? const Color(0x1A2563EB) : Colors.white, // bg-fp-primary/10
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: FpColores.borde),
      ),
      child: url.isEmpty
          ? letra
          : Image.network(
              url,
              fit: BoxFit.contain,
              semanticLabel: 'Logotipo de $nombre',
              // Si el logo no carga (sin red, URL vieja), la inicial.
              errorBuilder: (_, _, _) => letra,
            ),
    );
  }
}
