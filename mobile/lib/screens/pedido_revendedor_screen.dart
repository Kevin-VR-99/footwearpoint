import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../models/pedido_resumen.dart';
import '../models/vale.dart';
import '../providers/auth_provider.dart';
import '../providers/carrito_revendedor_provider.dart';
import '../services/api_service.dart';
import '../services/pedido_service.dart';
import '../widgets/selector_vale.dart';
import 'login_screen.dart';
import '../tema/fp_colores.dart';
import '../widgets/fp_componentes.dart';

/// El pedido acumulado del revendedor (E9-01 / E9-03). Lee el carrito de
/// [CarritoRevendedorProvider], ligado a la sesión (TG-165).
///
/// TG-165: también se le puede aplicar un vale, igual que al pedido directo,
/// y al enviarlo se ven los números que calculó el servidor.
class PedidoRevendedorScreen extends StatefulWidget {
  const PedidoRevendedorScreen({super.key});

  @override
  State<PedidoRevendedorScreen> createState() => _PedidoRevendedorScreenState();
}

class _PedidoRevendedorScreenState extends State<PedidoRevendedorScreen> {
  late final _api = context.read<AuthProvider>().api;

  bool _enviando = false;
  Vale? _valeSeleccionado;

  /// El pedido ya enviado. Mientras sea null se ve el carrito.
  PedidoResumen? _pedido;
  String? _avisoVale;

  void _enviarPedido() async {
    final carrito = context.read<CarritoRevendedorProvider>();
    if (carrito.vacio) return;

    setState(() => _enviando = true);

    try {
      // Mismos pasos que el pedido directo (crear, líneas, enviar): viven en
      // PedidoService. Tipo, dueño, sucursal y precio los pone el servidor.
      var pedido = await PedidoService(_api).crearYEnviar(
        lineas: [
          for (final item in carrito.items)
            (
              productoCampanaId: item.producto.id,
              varianteId: item.variante.varianteId,
              cantidad: item.cantidad,
            ),
        ],
      );

      // El pedido ya se envió: aunque el vale falle, no debe quedar en el
      // carrito (se enviaría dos veces).
      carrito.vaciar();

      String? aviso;

      final vale = _valeSeleccionado;
      if (vale != null) {
        final resultado = await aplicarValeAlPedido(api: _api, vale: vale, pedido: pedido);
        pedido = resultado.pedido;
        aviso = resultado.aviso;
      }

      if (!mounted) return;
      setState(() {
        _pedido = pedido;
        _avisoVale = aviso;
        _enviando = false;
      });
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

    final pedido = _pedido;
    if (pedido != null) {
      return Scaffold(
        appBar: AppBar(title: const Text('Pedido enviado'), bottom: const FpBordeMarca()),
        body: SafeArea(
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(16),
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 520),
                child: _Enviado(pedido: pedido, avisoVale: _avisoVale),
              ),
            ),
          ),
        ),
      );
    }

    return Scaffold(
      appBar: AppBar(title: const Text('Pedido Acumulado de Revendedor'), bottom: const FpBordeMarca()),
      body: items.isEmpty
          ? const Center(
              child: FpEstadoVacio(
                icono: Icons.shopping_cart_outlined,
                titulo: 'No hay productos agregados al pedido.',
                texto: 'Agrega productos desde el catálogo con "Agregar a pedido".',
              ),
            )
          : Column(
              children: [
                Expanded(
                  child: ListView.builder(
                    itemCount: items.length,
                    itemBuilder: (context, index) {
                      final item = items[index];
                      final precioUnitario = item.producto.precioMayorista ?? item.producto.precioMinoristaSugerido;

                      return Card(
                        margin: EdgeInsets.fromLTRB(16, index == 0 ? 16 : 6, 16, 6),
                        child: Padding(
                          padding: const EdgeInsets.all(12.0),
                          child: Row(
                            children: [
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(
                                      item.producto.nombre,
                                      style: const TextStyle(color: FpColores.sidebar, fontWeight: FontWeight.w600),
                                    ),
                                    const SizedBox(height: 4),
                                    Text(
                                      'Color: ${item.variante.colorParaMostrar} | Talla: ${item.variante.talla}',
                                      style: const TextStyle(color: FpColores.textoTenue, fontSize: 13),
                                    ),
                                    const SizedBox(height: 2),
                                    Text(
                                      'Precio revendedor: ${formatoPrecio(precioUnitario)}',
                                      style: TextStyle(color: tema.colorScheme.primary, fontWeight: FontWeight.w600),
                                    ),
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
                // Panel de abajo: blanco, con borde y sombra suaves (como las
                // tarjetas de la web).
                Container(
                  padding: const EdgeInsets.all(16),
                  decoration: const BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
                    border: Border(top: BorderSide(color: FpColores.borde)),
                    boxShadow: [BoxShadow(color: Color(0x14000000), blurRadius: 12, offset: Offset(0, -2))],
                  ),
                  child: SafeArea(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        Container(
                          padding: const EdgeInsets.all(10),
                          margin: const EdgeInsets.only(bottom: 12),
                          decoration: BoxDecoration(
                            color: FpColores.primario.withValues(alpha: 0.06),
                            borderRadius: BorderRadius.circular(12),
                            border: Border.all(color: FpColores.primario.withValues(alpha: 0.15)),
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
                        SelectorVale(
                          api: _api,
                          habilitado: !_enviando,
                          alCambiar: (vale) => _valeSeleccionado = vale,
                        ),
                        const SizedBox(height: 12),
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            const Text(
                              'Total estimado:',
                              style: TextStyle(color: FpColores.sidebar, fontSize: 16, fontWeight: FontWeight.w600),
                            ),
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

/// Después de enviar: los números que calculó el servidor, ya con el vale.
class _Enviado extends StatelessWidget {
  const _Enviado({required this.pedido, required this.avisoVale});

  final PedidoResumen pedido;
  final String? avisoVale;

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);
    final otrosPagos = pedido.pagado - pedido.pagadoConVales;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const FpIconoGrande(icono: Icons.check_rounded),
        const SizedBox(height: 12),
        Text(
          '¡Pedido enviado!',
          textAlign: TextAlign.center,
          style: tema.textTheme.headlineSmall?.copyWith(color: FpColores.sidebar, fontWeight: FontWeight.bold),
        ),
        const SizedBox(height: 4),
        Text(
          'Folio ${pedido.folio}',
          textAlign: TextAlign.center,
          style: tema.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w600),
        ),
        const SizedBox(height: 4),
        Text(
          'Enviado a la distribuidora a tu nombre.',
          textAlign: TextAlign.center,
          style: tema.textTheme.bodyMedium?.copyWith(color: tema.colorScheme.onSurfaceVariant),
        ),
        const SizedBox(height: 24),
        Card(
          margin: EdgeInsets.zero,
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              children: [
                _Fila(etiqueta: 'Total del pedido', valor: formatoPrecio(pedido.total)),
                // "pagado" ya incluye lo del vale: se separa para no contarlo dos veces.
                if (pedido.pagadoConVales > 0)
                  _Fila(etiqueta: 'Pagado con vale', valor: '-${formatoPrecio(pedido.pagadoConVales)}'),
                if (otrosPagos > 0.009)
                  _Fila(etiqueta: 'Otros pagos', valor: '-${formatoPrecio(otrosPagos)}'),
                const Divider(height: 24),
                _Fila(etiqueta: 'Pendiente por pagar', valor: formatoPrecio(pedido.saldo), destacado: true),
              ],
            ),
          ),
        ),
        const SizedBox(height: 16),
        Text(
          'Paga en mostrador; ahí registran tu pago. Te avisaremos cuando tu pedido llegue.',
          textAlign: TextAlign.center,
          style: tema.textTheme.bodyMedium,
        ),
        if (avisoVale != null) ...[
          const SizedBox(height: 12),
          AvisoError(mensaje: 'Tu pedido sí se envió, pero no se pudo aplicar el vale: $avisoVale'),
        ],
        const SizedBox(height: 24),
        FilledButton(
          onPressed: () => Navigator.of(context).popUntil((ruta) => ruta.isFirst),
          style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
          child: const Text('Volver al inicio'),
        ),
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

String formatoPrecio(double precio) {
  final partes = precio.toStringAsFixed(2).split('.');
  final entero = partes[0].replaceAllMapped(
    RegExp(r'\B(?=(\d{3})+(?!\d))'),
    (_) => ',',
  );

  return '\$$entero.${partes[1]}';
}