import 'package:flutter/material.dart';
import '../models/producto_catalogo.dart';

/// Pantalla para registrar un pedido directo bajo pedido (E8-01).
class CrearPedidoScreen extends StatefulWidget {
  const CrearPedidoScreen({
    super.key,
    required this.producto,
    required this.variante,
  });

  final ProductoCatalogo producto;
  final VarianteCatalogo variante;

  @override
  State<CrearPedidoScreen> createState() => _CrearPedidoScreenState();
}

class _CrearPedidoScreenState extends State<CrearPedidoScreen> {
  bool _enviando = false;

  void _confirmarPedido() async {
    setState(() => _enviando = true);

    // Simulamos la petición al servidor para verificar disponibilidad y registrar
    await Future.delayed(const Duration(seconds: 15));

    if (!mounted) return;

    setState(() => _enviando = false);

    // Mensaje temporal de éxito y regreso al catálogo
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('¡Pedido directo registrado con éxito!')),
    );
    Navigator.pop(context); // Regresa al detalle
    Navigator.pop(context); // Regresa al catálogo
  }

  @override
  Widget build(BuildContext context) {
    final producto = widget.producto;
    final variante = widget.variante;
    final tema = Theme.of(context);

    return Scaffold(
      appBar: AppBar(title: const Text('Confirmar Pedido Directo')),
      body: Padding(
        padding: const EdgeInsets.all(16.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16.0),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      producto.nombre,
                      style: tema.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.bold),
                    ),
                    const SizedBox(height: 8),
                    Text('Modelo: ${producto.modelo}'),
                    Text('Color: ${variante.color}'),
                    Text('Talla: ${variante.talla}'),
                    const Divider(height: 24),
                    Text(
                      'Precio sugerido: ${formatoPrecio(producto.precioMinoristaSugerido)}',
                      style: tema.textTheme.titleMedium?.copyWith(
                        color: tema.colorScheme.primary,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                  ],
                ),
              ),
            ),
            const Spacer(),
            FilledButton.icon(
              onPressed: _enviando ? null : _confirmarPedido,
              icon: _enviando
                  ? const SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                    )
                  : const Icon(Icons.check_circle_outline),
              label: Text(_enviando ? 'Verificando con proveedor...' : 'Confirmar Pedido'),
            ),
          ],
        ),
      ),
    );
  }
}
/// 1600 -> "$1,600.00"
String formatoPrecio(double precio) {
  final partes = precio.toStringAsFixed(2).split('.');
  final entero = partes[0].replaceAllMapped(
    RegExp(r'\B(?=(\d{3})+(?!\d))'),
    (_) => ',',
  );

  return '\$$entero.${partes[1]}';
}