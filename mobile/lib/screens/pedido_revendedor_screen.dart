import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../models/producto_catalogo.dart';
import '../models/item_pedido_revendedor.dart';
import '../providers/auth_provider.dart';
import '../services/api_service.dart';

class PedidoRevendedorScreen extends StatefulWidget {
  const PedidoRevendedorScreen({super.key, required this.itemsIniciales});

  final List<ItemPedidoRevendedor> itemsIniciales;

  @override
  State<PedidoRevendedorScreen> createState() => _PedidoRevendedorScreenState();
}

class _PedidoRevendedorScreenState extends State<PedidoRevendedorScreen> {
  late List<ItemPedidoRevendedor> _items;
  bool _enviando = false;

  @override
  void initState() {
    super.initState();
    _items = widget.itemsIniciales;
  }

  void _enviarPedido() async {
    if (_items.isEmpty) return;

    setState(() => _enviando = true);

    try {
      final api = context.read<AuthProvider>().api;

      // 1. Creamos el pedido maestro base
      final respuestaPedido = await api.post('/pedidos', cuerpo: {
        'tipo': 'revendedor',
        'propietario_id': 1,
        'sucursal_id': 1,
      });

      final pedidoId = respuestaPedido['data']?['id'] ?? respuestaPedido['id'];

      if (pedidoId == null) {
        throw ApiException('No se pudo obtener el ID del pedido creado.');
      }

      // 2. Agregamos cada línea utilizando el producto_campana_id correcto y la variante correspondiente a ese producto
      for (var item in _items) {
        await api.post('/pedidos/$pedidoId/lineas', cuerpo: {
          'producto_campana_id': item.producto.id,
          'variante_id': item.variante.varianteId,
          'cantidad': item.cantidad,
        });
      }

      // 3. Enviamos formalmente el pedido a la distribuidora
      await api.post('/pedidos/$pedidoId/enviar');

      if (!mounted) return;

      setState(() => _enviando = false);

      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('¡Pedido enviado a la distribuidora a nombre del revendedor con éxito!')),
      );
      
      _items.clear();
      Navigator.popUntil(context, (route) => route.isFirst);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _enviando = false);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Error del servidor: ${e.mensaje}')),
      );
    } catch (e) {
      if (!mounted) return;
      setState(() => _enviando = false);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Error de conexión al enviar el pedido: $e')),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);
    final totalGeneral = _items.fold<double>(0, (suma, item) => suma + item.subtotal);

    return Scaffold(
      appBar: AppBar(title: const Text('Pedido Acumulado de Revendedor')),
      body: _items.isEmpty
          ? const Center(child: Text('No hay productos agregados al pedido.'))
          : Column(
              children: [
                Expanded(
                  child: ListView.builder(
                    itemCount: _items.length,
                    itemBuilder: (context, index) {
                      final item = _items[index];
                      final precioUnitario = item.producto.precioMayorista ?? item.producto.precioMinoristaSugerido;

                      return Card(
                        margin: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                        child: Padding(
                          padding: const EdgeInsets.all(12.0),
                          child: Row(
                            children: [
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(item.producto.nombre, style: const TextStyle(fontWeight: FontWeight.bold)),
                                    const SizedBox(height: 4),
                                    Text('Color: ${item.variante.colorParaMostrar} | Talla: ${item.variante.talla}'),
                                    Text('Precio revendedor: ${formatoPrecio(precioUnitario)}', style: TextStyle(color: tema.colorScheme.primary)),
                                  ],
                                ),
                              ),
                              Row(
                                children: [
                                  IconButton(
                                    icon: const Icon(Icons.remove_circle_outline),
                                    onPressed: item.cantidad > 1
                                        ? () => setState(() => item.cantidad--)
                                        : null,
                                  ),
                                  Text('${item.cantidad}', style: const TextStyle(fontWeight: FontWeight.bold)),
                                  IconButton(
                                    icon: const Icon(Icons.add_circle_outline),
                                    onPressed: () => setState(() => item.cantidad++),
                                  ),
                                ],
                              ),
                            ],
                          ),
                        ),
                      );
                    },
                  ),
                ),
                Container(
                  padding: const EdgeInsets.all(16),
                  decoration: BoxDecoration(
                    color: tema.colorScheme.surfaceContainerHighest,
                    borderRadius: const BorderRadius.vertical(top: Radius.circular(16)),
                  ),
                  child: SafeArea(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        Container(
                          padding: const EdgeInsets.all(10),
                          margin: const EdgeInsets.only(bottom: 12),
                          decoration: BoxDecoration(
                            color: tema.colorScheme.surface,
                            borderRadius: BorderRadius.circular(8),
                          ),
                          child: Row(
                            children: [
                              Icon(Icons.info_outline, size: 20, color: tema.colorScheme.primary),
                              const SizedBox(width: 8),
                              const Expanded(
                                child: Text(
                                  'El pedido se envía a nombre del revendedor (sin reserva de stock local).',
                                  style: TextStyle(fontSize: 12),
                                ),
                              ),
                            ],
                          ),
                        ),
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            const Text('Total estimado:', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
                            Text(formatoPrecio(totalGeneral), style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: tema.colorScheme.primary)),
                          ],
                        ),
                        const SizedBox(height: 12),
                        FilledButton.icon(
                          onPressed: _enviando ? null : _enviarPedido,
                          icon: _enviando
                              ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                              : const Icon(Icons.send),
                          label: Text(_enviando ? 'Enviando a distribuidora...' : 'Enviar Pedido Completo'),
                        ),
                      ],
                    ),
                  ),
                ),
              ],
            ),
    );
  }
}

String formatoPrecio(double precio) {
  final partes = precio.toStringAsFixed(2).split('.');
  final entero = partes[0].replaceAllMapped(
    RegExp(r'\B(?=(\d{3})+(?!\d))'),
    (_) => ',',
  );

  return '\$$entero.${partes[1]}';
}