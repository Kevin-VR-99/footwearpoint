import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:provider/provider.dart';

import '../models/pedido_resumen.dart';
import '../providers/auth_provider.dart';
import '../services/api_service.dart';

/// Banco, CLABE y titular para quien debe pagar este pedido.
/// Subir la foto no registra el pago.
class DatosTransferenciaPedido extends StatefulWidget {
  const DatosTransferenciaPedido({super.key, required this.pedido, required this.alSubir});

  final PedidoResumen pedido;
  final Future<void> Function() alSubir;

  @override
  State<DatosTransferenciaPedido> createState() => _DatosTransferenciaPedidoState();
}

class _DatosTransferenciaPedidoState extends State<DatosTransferenciaPedido> {
  bool _subiendo = false;
  String? _aviso;

  Future<void> _subir() async {
    final archivo = await ImagePicker().pickImage(source: ImageSource.gallery, imageQuality: 85);
    if (archivo == null || !mounted) return;

    setState(() {
      _subiendo = true;
      _aviso = null;
    });

    try {
      final api = context.read<AuthProvider>().api;
      await api.subirArchivo(
        'pedidos/${widget.pedido.id}/comprobante-transferencia',
        campo: 'comprobante',
        bytes: await archivo.readAsBytes(),
        nombreArchivo: archivo.name,
      );
      if (!mounted) return;
      setState(() => _aviso = 'Comprobante recibido. El pago cuenta cuando la distribuidora lo confirma.');
      await widget.alSubir();
    } on ApiException catch (e) {
      if (mounted) setState(() => _aviso = e.mensaje);
    } catch (e) {
      if (mounted) setState(() => _aviso = 'No se pudo subir el comprobante.');
    } finally {
      if (mounted) setState(() => _subiendo = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final datos = widget.pedido.transferencia;
    if (datos == null) return const SizedBox.shrink();

    return Card(
      margin: const EdgeInsets.only(top: 12),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('Pagar por transferencia', style: TextStyle(fontWeight: FontWeight.w600)),
            const SizedBox(height: 8),
            Text('Banco: ${datos.banco}'),
            Text('CLABE: ${datos.clabe}'),
            Text('Titular: ${datos.titular}'),
            Text('Monto: \$${datos.monto.toStringAsFixed(2)}'),
            Text('Concepto: ${datos.concepto}'),
            const SizedBox(height: 8),
            Text(datos.aviso, style: const TextStyle(fontSize: 12)),
            if (datos.comprobanteNombre != null) ...[
              const SizedBox(height: 8),
              Text('Comprobante: ${datos.comprobanteNombre}. No cuenta como pago hasta que se confirme.'),
            ],
            const SizedBox(height: 8),
            OutlinedButton(
              onPressed: _subiendo ? null : _subir,
              child: Text(_subiendo ? 'Subiendo...' : 'Subir comprobante'),
            ),
            if (_aviso != null) ...[
              const SizedBox(height: 8),
              Text(_aviso!),
            ],
          ],
        ),
      ),
    );
  }
}
