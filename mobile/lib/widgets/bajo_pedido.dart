import 'package:flutter/material.dart';

import '../tema/fp_colores.dart';

/// TG-266: cómo se muestra "bajo pedido" en toda la app.
///
/// Una talla bajo pedido SÍ se puede pedir (TG-214); solo hay que dejar claro
/// que tarda más. Mismo texto que la tienda web.
const textoBajoPedido = 'Se encarga a la fábrica y tarda más en llegar.';

/// Para una lista (carrito, pedido) con una o más tallas bajo pedido.
const textoTallasBajoPedido = 'Las tallas bajo pedido se encargan a la fábrica y tardan más en llegar.';

/// Insignia naranja «Bajo pedido» (o el texto que se le pase).
class InsigniaBajoPedido extends StatelessWidget {
  const InsigniaBajoPedido({super.key, this.texto = 'Bajo pedido'});

  final String texto;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: FpColores.insigniaAvisoFondo,
        borderRadius: BorderRadius.circular(999),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(Icons.schedule, size: 13, color: FpColores.insigniaAvisoTexto),
          const SizedBox(width: 4),
          Text(
            texto,
            style: const TextStyle(color: FpColores.insigniaAvisoTexto, fontSize: 12, fontWeight: FontWeight.w600),
          ),
        ],
      ),
    );
  }
}

/// Nota que explica qué significa "bajo pedido".
class AvisoBajoPedido extends StatelessWidget {
  const AvisoBajoPedido({super.key, required this.texto});

  final String texto;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: FpColores.insigniaAvisoFondo,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Icon(Icons.schedule, size: 18, color: FpColores.insigniaAvisoTexto),
          const SizedBox(width: 8),
          Expanded(
            child: Text(texto, style: const TextStyle(color: FpColores.insigniaAvisoTexto, fontSize: 13)),
          ),
        ],
      ),
    );
  }
}
