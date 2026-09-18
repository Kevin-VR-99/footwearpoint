import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../models/estado_pedido.dart';
import '../models/pedido_resumen.dart';
import '../providers/auth_provider.dart';
import '../services/api_service.dart';
import '../services/pedido_service.dart';
import 'producto_detalle_screen.dart';

/// El detalle de un pedido propio (TG-165): su estado explicado, lo que se
/// pidió, cuánto se ha pagado y cuánto falta. Recibe el id y lo pide al
/// servidor, así también se puede abrir desde una notificación.
class PedidoDetalleScreen extends StatefulWidget {
  const PedidoDetalleScreen({super.key, required this.pedidoId});

  final int pedidoId;

  @override
  State<PedidoDetalleScreen> createState() => _PedidoDetalleScreenState();
}

class _PedidoDetalleScreenState extends State<PedidoDetalleScreen> {
  late final _pedidos = PedidoService(context.read<AuthProvider>().api);

  PedidoResumen? _pedido;
  bool _cargando = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _cargar();
  }

  Future<void> _cargar() async {
    try {
      final pedido = await _pedidos.ver(widget.pedidoId);
      if (!mounted) return;
      setState(() {
        _pedido = pedido;
        _error = null;
        _cargando = false;
      });
    } on ApiException catch (e) {
      // 404 si no existe o no es suyo (el servidor no distingue, a propósito).
      if (!mounted) return;
      setState(() {
        _error = e.codigoHttp == 404 ? 'No encontramos ese pedido.' : e.mensaje;
        _cargando = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final pedido = _pedido;

    return Scaffold(
      appBar: AppBar(title: Text(pedido?.folio ?? 'Pedido')),
      body: SafeArea(
        child: _cargando
            ? const Center(child: CircularProgressIndicator())
            : pedido == null
                ? Center(
                    child: Padding(
                      padding: const EdgeInsets.all(24),
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Text(_error ?? 'No se pudo cargar el pedido.', textAlign: TextAlign.center),
                          const SizedBox(height: 16),
                          FilledButton.icon(
                            onPressed: () {
                              setState(() => _cargando = true);
                              _cargar();
                            },
                            icon: const Icon(Icons.refresh),
                            label: const Text('Reintentar'),
                          ),
                        ],
                      ),
                    ),
                  )
                : RefreshIndicator(onRefresh: _cargar, child: _detalle(context, pedido)),
      ),
    );
  }

  Widget _detalle(BuildContext context, PedidoResumen pedido) {
    final tema = Theme.of(context);
    final estado = EstadoPedido.de(pedido.estado);
    final otrosPagos = pedido.pagado - pedido.pagadoConVales;

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        // Estado
        Card(
          margin: EdgeInsets.zero,
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(child: Text('Estado', style: tema.textTheme.titleMedium)),
                    EtiquetaEstadoPedido(estado: pedido.estado),
                  ],
                ),
                if (estado.descripcion != null) ...[
                  const SizedBox(height: 8),
                  Text(estado.descripcion!, style: tema.textTheme.bodyLarge),
                ],
                if (pedido.fecha != null) ...[
                  const SizedBox(height: 8),
                  Text(
                    'Enviado el ${formatoFecha(pedido.fecha!)}',
                    style: tema.textTheme.bodySmall?.copyWith(color: tema.colorScheme.onSurfaceVariant),
                  ),
                ],
              ],
            ),
          ),
        ),
        const SizedBox(height: 16),

        // Productos
        Text('Productos', style: tema.textTheme.titleMedium),
        const SizedBox(height: 8),
        for (final linea in pedido.lineas)
          Card(
            margin: const EdgeInsets.only(bottom: 8),
            child: ListTile(
              title: Text(linea.productoNombre, style: const TextStyle(fontWeight: FontWeight.w600)),
              subtitle: Text(
                'Modelo ${linea.modelo} · Talla ${linea.talla} · ${linea.color}\n'
                '${linea.cantidad} × ${formatoPrecio(linea.precioUnitario)}',
              ),
              isThreeLine: true,
              trailing: Text(formatoPrecio(linea.subtotal), style: const TextStyle(fontWeight: FontWeight.w600)),
            ),
          ),
        const SizedBox(height: 8),

        // Pagos
        Card(
          margin: EdgeInsets.zero,
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              children: [
                _Fila(etiqueta: 'Total del pedido', valor: formatoPrecio(pedido.total)),
                if (pedido.pagadoConVales > 0)
                  _Fila(etiqueta: 'Pagado con vale', valor: '-${formatoPrecio(pedido.pagadoConVales)}'),
                if (otrosPagos > 0.009)
                  _Fila(etiqueta: 'Pagado en mostrador', valor: '-${formatoPrecio(otrosPagos)}'),
                const Divider(height: 24),
                if (!pedido.esRevendedor && pedido.anticipoPendiente > 0)
                  _Fila(
                    etiqueta: 'Anticipo pendiente',
                    valor: formatoPrecio(pedido.anticipoPendiente),
                    destacado: true,
                  ),
                _Fila(
                  etiqueta: 'Saldo pendiente',
                  valor: formatoPrecio(pedido.saldo),
                  destacado: pedido.esRevendedor || pedido.anticipoPendiente <= 0,
                ),
              ],
            ),
          ),
        ),
        if (pedido.saldo > 0) ...[
          const SizedBox(height: 12),
          Row(
            children: [
              Icon(Icons.storefront_outlined, size: 20, color: tema.colorScheme.primary),
              const SizedBox(width: 8),
              const Expanded(child: Text('Los pagos se hacen y se registran en mostrador.')),
            ],
          ),
        ],

        if (pedido.pagos.isNotEmpty) ...[
          const SizedBox(height: 16),
          Text('Pagos registrados', style: tema.textTheme.titleMedium),
          const SizedBox(height: 8),
          for (final pago in pedido.pagos)
            ListTile(
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.payments_outlined),
              title: Text('${pago.tipoParaMostrar} · ${formatoPrecio(pago.monto)}'),
              subtitle: Text([
                pago.folio,
                if (pago.metodo.isNotEmpty) pago.metodo,
                if (pago.fecha != null) formatoFecha(pago.fecha!, conHora: false),
              ].join(' · ')),
            ),
        ],
      ],
    );
  }
}

class _Fila extends StatelessWidget {
  const _Fila({required this.etiqueta, required this.valor, this.destacado = false});

  final String etiqueta;
  final String valor;
  final bool destacado;

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);
    final estilo = destacado
        ? tema.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.bold, color: tema.colorScheme.primary)
        : tema.textTheme.bodyLarge;

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        children: [
          Expanded(child: Text(etiqueta, style: estilo)),
          Text(valor, style: estilo),
        ],
      ),
    );
  }
}
