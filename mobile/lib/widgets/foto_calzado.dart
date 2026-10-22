import 'package:flutter/material.dart';

import '../screens/producto_detalle_screen.dart';
import '../tema/fp_colores.dart';

/// Foto chica del calzado. Si hay imagen, un toque la abre grande,
/// igual que la miniatura de Stock local en el panel.
class FotoCalzado extends StatelessWidget {
  const FotoCalzado({super.key, required this.url, this.nombre = 'Producto', this.tamano = 44});

  final String? url;
  final String nombre;
  final double tamano;

  static void abrir(BuildContext context, String url, String nombre) {
    showDialog<void>(
      context: context,
      builder: (context) => Dialog(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            AspectRatio(
              aspectRatio: 1,
              child: ImagenProducto(url: url, anchoCache: 800),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 8, 12, 12),
              child: Text(
                nombre,
                textAlign: TextAlign.center,
                style: const TextStyle(fontWeight: FontWeight.w600, color: FpColores.texto),
              ),
            ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final foto = ClipRRect(
      borderRadius: BorderRadius.circular(8),
      child: SizedBox(
        width: tamano,
        height: tamano,
        child: ImagenProducto(url: url, anchoCache: 200),
      ),
    );

    if (url == null || url!.isEmpty) return foto;

    return GestureDetector(
      onTap: () => abrir(context, url!, nombre),
      child: foto,
    );
  }
}
