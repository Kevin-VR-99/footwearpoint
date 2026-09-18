import 'package:flutter/material.dart';

/// Cómo se muestra cada estado de un pedido en la app (TG-165, "Mis pedidos").
///
/// Los nombres y los colores son los mismos del panel web
/// (resources/views/components/ui/insignia-estado.blade.php), para que el
/// cliente y el empleado hablen de lo mismo. La frase es solo una explicación
/// del nombre del estado para quien no conoce el proceso.
class EstadoPedido {
  const EstadoPedido._(this.etiqueta, this.tono, [this.descripcion]);

  final String etiqueta;
  final TonoEstado tono;
  final String? descripcion;

  static const _estados = <String, EstadoPedido>{
    'borrador': EstadoPedido._('Borrador', TonoEstado.neutral),
    'descartado': EstadoPedido._('Descartado', TonoEstado.neutral),
    'colocado': EstadoPedido._('Colocado', TonoEstado.info, 'La distribuidora recibió tu pedido.'),
    'en_revision': EstadoPedido._('En revisión', TonoEstado.info, 'La distribuidora está revisando tu pedido.'),
    'confirmado': EstadoPedido._('Confirmado', TonoEstado.info, 'La distribuidora confirmó tu pedido.'),
    'incluido_en_ciclo': EstadoPedido._('En ciclo', TonoEstado.info, 'Tu pedido entrará en el próximo pedido a fábrica.'),
    'solicitado_fabrica': EstadoPedido._('Solicitado fábrica', TonoEstado.info, 'Tu pedido ya se le pidió a la fábrica.'),
    'en_transito': EstadoPedido._('En tránsito', TonoEstado.info, 'Tu pedido viene en camino a la distribuidora.'),
    'recibido_distribuidora': EstadoPedido._('Recibido', TonoEstado.info, 'Tu pedido ya llegó a la distribuidora.'),
    'parcialmente_disponible': EstadoPedido._('Parc. disponible', TonoEstado.advertencia),
    'listo_entrega': EstadoPedido._('Listo entrega', TonoEstado.advertencia, 'Tu pedido está listo para que pases por él.'),
    'vencido_recoleccion': EstadoPedido._('Vencido recolección', TonoEstado.advertencia),
    'no_surtido': EstadoPedido._('No surtido', TonoEstado.advertencia),
    'entregado': EstadoPedido._('Entregado', TonoEstado.exito, 'Pedido entregado.'),
    'rechazado': EstadoPedido._('Rechazado', TonoEstado.peligro),
  };

  static EstadoPedido de(String estado) =>
      _estados[estado] ?? EstadoPedido._(estado.replaceAll('_', ' '), TonoEstado.neutral);
}

enum TonoEstado {
  neutral,
  info,
  advertencia,
  exito,
  peligro;

  /// (fondo, texto) de la etiqueta.
  (Color, Color) colores() => switch (this) {
    TonoEstado.neutral => (Colors.grey.shade200, Colors.grey.shade800),
    TonoEstado.info => (Colors.blue.shade50, Colors.blue.shade800),
    TonoEstado.advertencia => (Colors.orange.shade50, Colors.orange.shade900),
    TonoEstado.exito => (Colors.green.shade50, Colors.green.shade800),
    TonoEstado.peligro => (Colors.red.shade50, Colors.red.shade800),
  };
}

/// La etiqueta de color de un estado, como la del panel web.
class EtiquetaEstadoPedido extends StatelessWidget {
  const EtiquetaEstadoPedido({super.key, required this.estado});

  final String estado;

  @override
  Widget build(BuildContext context) {
    final info = EstadoPedido.de(estado);
    final (fondo, texto) = info.tono.colores();

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(color: fondo, borderRadius: BorderRadius.circular(16)),
      child: Text(
        info.etiqueta,
        style: TextStyle(color: texto, fontSize: 12, fontWeight: FontWeight.w600),
      ),
    );
  }
}
