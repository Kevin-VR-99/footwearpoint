import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../models/pedido_resumen.dart';
import '../models/vale.dart';
import '../screens/producto_detalle_screen.dart';
import '../services/api_service.dart';
import '../services/pedido_service.dart';
import '../services/vale_service.dart';

/// Solo los vales que el servidor aceptaría (AplicarValeAction): activos,
/// con saldo y sin vencer.
List<Vale> valesUsables(List<Vale> vales, {DateTime? ahora}) {
  final momento = ahora ?? DateTime.now();

  return vales
      .where((v) =>
          v.estado == 'activo' &&
          v.saldoActual > 0 &&
          (v.fechaVencimiento == null || v.fechaVencimiento!.isAfter(momento)))
      .toList();
}

/// Aplica [vale] a un pedido YA ENVIADO (a un borrador no se puede) y lo
/// vuelve a pedir, para mostrar pagado, saldo y anticipo ya con el vale.
///
/// El servidor solo toma lo que falta por pagar y lo cuenta como pago,
/// también del anticipo (TG-167); la app pide lo mismo desde aquí.
///
/// Si el vale falla, el pedido ya se envió y no se pierde: se regresa tal
/// cual, con el motivo en `aviso`.
Future<({PedidoResumen pedido, String? aviso})> aplicarValeAlPedido({
  required ApiService api,
  required Vale vale,
  required PedidoResumen pedido,
}) async {
  final monto = math.min(vale.saldoActual, pedido.saldo);
  if (monto <= 0) return (pedido: pedido, aviso: null);

  try {
    await ValeService(api: api).aplicarVale(valeId: vale.id, monto: monto, pedidoId: pedido.id);

    return (pedido: await PedidoService(api).ver(pedido.id), aviso: null);
  } on ApiException catch (e) {
    return (
      pedido: pedido,
      aviso: e.errores.values.isNotEmpty ? e.errores.values.first.first : e.mensaje,
    );
  }
}

/// "¿Quieres usar un vale?" (E12-03). Lo usan el pedido directo y el pedido
/// acumulado del revendedor (TG-165), para que los dos ofrezcan los mismos
/// vales. Carga los vales del usuario y avisa con [alCambiar] cuál eligió.
class SelectorVale extends StatefulWidget {
  const SelectorVale({
    super.key,
    required this.api,
    required this.alCambiar,
    this.habilitado = true,
  });

  final ApiService api;
  final ValueChanged<Vale?> alCambiar;

  /// En false (por ejemplo, mientras se envía) no deja cambiar el vale.
  final bool habilitado;

  @override
  State<SelectorVale> createState() => _SelectorValeState();
}

class _SelectorValeState extends State<SelectorVale> {
  bool _cargando = true;
  List<Vale> _vales = [];
  Vale? _seleccionado;

  @override
  void initState() {
    super.initState();
    _cargar();
  }

  Future<void> _cargar() async {
    try {
      final vales = await ValeService(api: widget.api).listarValesVigentes();
      if (!mounted) return;
      setState(() {
        _vales = valesUsables(vales);
        _cargando = false;
      });
    } on ApiException {
      // Sin vales no se detiene el pedido: solo no se ofrecen.
      if (mounted) setState(() => _cargando = false);
    }
  }

  void _elegir(Vale? vale) {
    setState(() => _seleccionado = vale);
    widget.alCambiar(vale);
  }

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);

    return Card(
      margin: EdgeInsets.zero,
      color: tema.colorScheme.surfaceContainerHighest.withValues(alpha: 0.3),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('¿Quieres usar un vale?', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
            const SizedBox(height: 4),
            Text(
              'Se aplica a este pedido al enviarlo, como máximo por lo que debas.',
              style: tema.textTheme.bodySmall?.copyWith(color: tema.colorScheme.onSurfaceVariant),
            ),
            const SizedBox(height: 12),
            if (_cargando)
              const LinearProgressIndicator()
            else if (_vales.isEmpty)
              const Text('No tienes vales vigentes.', style: TextStyle(color: Colors.grey))
            else
              Row(
                children: [
                  Expanded(
                    // `value` (y no `initialValue`) a propósito: "Quitar vale"
                    // limpia la selección desde el código.
                    child: DropdownButtonFormField<Vale?>(
                      // ignore: deprecated_member_use
                      value: _seleccionado,
                      isExpanded: true,
                      decoration: const InputDecoration(
                        labelText: 'Vale (opcional)',
                        border: OutlineInputBorder(),
                      ),
                      items: [
                        const DropdownMenuItem<Vale?>(value: null, child: Text('Sin vale')),
                        for (final vale in _vales)
                          DropdownMenuItem<Vale?>(
                            value: vale,
                            child: Text(
                              '${vale.folio} · disponible ${formatoPrecio(vale.saldoActual)}',
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                      ],
                      onChanged: widget.habilitado ? _elegir : null,
                    ),
                  ),
                  if (_seleccionado != null) ...[
                    const SizedBox(width: 8),
                    IconButton(
                      icon: Icon(Icons.clear, color: tema.colorScheme.error),
                      tooltip: 'Quitar vale',
                      onPressed: widget.habilitado ? () => _elegir(null) : null,
                    ),
                  ],
                ],
              ),
          ],
        ),
      ),
    );
  }
}
