import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../providers/auth_provider.dart';
import '../providers/carrito_revendedor_provider.dart';
import '../services/api_service.dart';

/// Sucursal a la que se mandan los pedidos desde la app.
///
/// PENDIENTE (backend, Kevin): la app no tiene forma de saber la sucursal de
/// su distribuidora, y el servidor la busca dentro de la distribuidora del
/// usuario. Con 1 funciona en los datos demo, pero en otra distribuidora
/// fallaría con "La sucursal no existe". Se deja en un solo lugar para
/// cambiarlo cuando el servidor la resuelva solo o la mande en auth/me.
const sucursalPedidosApp = 1;

/// El pedido acumulado del revendedor (E9-01 / E9-03). Lee el carrito de
/// [CarritoRevendedorProvider], ligado a la sesión (TG-165).
class PedidoRevendedorScreen extends StatefulWidget {
  const PedidoRevendedorScreen({super.key});

  @override
  State<PedidoRevendedorScreen> createState() => _PedidoRevendedorScreenState();
}

class _PedidoRevendedorScreenState extends State<PedidoRevendedorScreen> {
  bool _enviando = false;

  void _enviarPedido() async {
    final carrito = context.read<CarritoRevendedorProvider>();
    if (carrito.vacio) return;

    setState(() => _enviando = true);

    try {
      final api = context.read<AuthProvider>().api;

      // 1. Creamos el pedido maestro base
      final respuestaPedido = await api.post('/pedidos', cuerpo: {
        'tipo': 'revendedor',
        // El servidor usa al revendedor de la sesión; se manda porque el
        // endpoint lo exige (lo comparte con la web).
        'propietario_id': 1,
        'sucursal_id': sucursalPedidosApp,
      });

      final pedidoId = respuestaPedido['data']?['id'] ?? respuestaPedido['id'];

      if (pedidoId == null) {
        throw ApiException('No se pudo obtener el ID del pedido creado.');
      }

      // 2. Agregamos cada línea utilizando el producto_campana_id correcto y la variante correspondiente a ese producto
      for (final item in carrito.items) {
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

      carrito.vaciar();
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
    final carrito = context.watch<CarritoRevendedorProvider>();
    final items = carrito.items;
    final totalGeneral = carrito.total;

    return Scaffold(
      appBar: AppBar(title: const Text('Pedido Acumulado de Revendedor')),
      body: items.isEmpty
          ? const Center(child: Text('No hay productos agregados al pedido.'))
          : Column(
              children: [
                Expanded(
                  child: ListView.builder(
                    itemCount: items.length,
                    itemBuilder: (context, index) {
                      final item = items[index];
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
                                    tooltip: 'Una pieza menos',
                                    onPressed: item.cantidad > 1
                                        ? () => carrito.cambiarCantidad(item, item.cantidad - 1)
                                        : null,
                                  ),
                                  Text('${item.cantidad}', style: const TextStyle(fontWeight: FontWeight.bold)),
                                  IconButton(
                                    icon: const Icon(Icons.add_circle_outline),
                                    tooltip: 'Una pieza más',
                                    onPressed: () => carrito.cambiarCantidad(item, item.cantidad + 1),
                                  ),
                                  // Antes no había forma de sacar un producto del carrito.
                                  IconButton(
                                    icon: Icon(Icons.delete_outline, color: tema.colorScheme.error),
                                    tooltip: 'Quitar del pedido',
                                    onPressed: _enviando ? null : () => carrito.quitar(item),
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