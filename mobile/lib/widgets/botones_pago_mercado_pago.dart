import 'package:flutter/material.dart';

import '../models/pedido_resumen.dart';
import '../screens/pago_mercado_pago_screen.dart';

/// "Pagar … con tarjeta" y/o "Verificar pago" según lo que diga el servidor
/// del pedido. La tarjeta se cobra en Mercado Pago. No muestra nada si no
/// hay nada que pagar ni verificar. [alTerminar] se llama si en la pantalla
/// de pago cambió algo.
class BotonesPagoMercadoPago extends StatelessWidget {
  const BotonesPagoMercadoPago({super.key, required this.pedido, this.alTerminar});

  final PedidoResumen pedido;
  final VoidCallback? alTerminar;

  Future<void> _abrir(BuildContext context, TipoPagoMercadoPago tipo, {bool verificar = false}) async {
    final cambio = await Navigator.of(context).push<bool>(
      MaterialPageRoute(
        builder: (_) => PagoMercadoPagoScreen(pedido: pedido, tipo: tipo, verificarAlAbrir: verificar),
      ),
    );
    if (cambio == true) alTerminar?.call();
  }

  @override
  Widget build(BuildContext context) {
    final porVerificar = pedido.pagoMercadoPagoPorVerificar;
    final disponible = pedido.pagoMercadoPagoDisponible;

    if (porVerificar == null && disponible == null) return const SizedBox.shrink();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (porVerificar != null)
          OutlinedButton.icon(
            onPressed: () => _abrir(context, porVerificar, verificar: true),
            style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(48)),
            icon: const Icon(Icons.refresh),
            label: const Text('Verificar pago'),
          ),
        if (porVerificar != null && disponible != null) const SizedBox(height: 8),
        if (disponible != null)
          FilledButton.icon(
            onPressed: () => _abrir(context, disponible),
            style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(48)),
            icon: const Icon(Icons.credit_card),
            label: Text(disponible.textoBoton),
          ),
      ],
    );
  }
}
