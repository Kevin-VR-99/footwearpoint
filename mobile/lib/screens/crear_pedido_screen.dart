import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../models/pedido_resumen.dart';
import '../models/producto_catalogo.dart';
import '../models/vale.dart';
import '../providers/auth_provider.dart';
import '../services/api_service.dart';
import '../services/pedido_service.dart';
import '../widgets/selector_vale.dart';
import 'login_screen.dart';
import 'producto_detalle_screen.dart';

/// Pedido directo de una variante (E8-01 / E8-02), con vale opcional (E12-03).
///
/// Corrección TG-165: antes esta pantalla NO creaba el pedido (esperaba un
/// segundo y usaba un id inventado, 1) y pedía método de pago y referencia.
/// Decisión del equipo (opción A): la app crea el pedido de verdad y le dice
/// al cliente cuánto anticipo pagar EN MOSTRADOR; los pagos solo los registra
/// el mostrador (E8-02). Precio y anticipo los calcula el servidor.
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
  // Con el ApiService de AuthProvider (no uno propio): así, si la sesión
  // expira aquí, el 401 regresa al login como en el resto de la app.
  late final _api = context.read<AuthProvider>().api;
  late final _pedidos = PedidoService(_api);

  static const _cantidadMaxima = 20;

  int _cantidad = 1;
  Vale? _valeSeleccionado;

  bool _enviando = false;
  String? _error;

  /// El pedido ya enviado. Mientras sea null se ve el formulario.
  PedidoResumen? _pedido;
  String? _avisoVale;

  bool get _esRevendedor => widget.producto.precioMayorista != null;

  /// Solo para orientar antes de enviar. El total de verdad lo calcula el
  /// servidor según el tipo de pedido (TG-166).
  double get _precioUnitarioEstimado =>
      _esRevendedor ? widget.producto.precioMayorista! : widget.producto.precioMinoristaSugerido;

  Future<void> _enviar() async {
    setState(() {
      _enviando = true;
      _error = null;
    });

    try {
      var pedido = await _pedidos.crearYEnviar(
        lineas: [
          (
            productoCampanaId: widget.producto.id,
            varianteId: widget.variante.varianteId,
            cantidad: _cantidad,
          ),
        ],
      );

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
      setState(() {
        _enviando = false;
        _error = e.errores.values.isNotEmpty ? e.errores.values.first.first : e.mensaje;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final pedido = _pedido;

    return Scaffold(
      appBar: AppBar(title: Text(pedido == null ? 'Pedido directo' : 'Pedido enviado')),
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(16),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 520),
              child: pedido == null ? _formulario(context) : _enviado(context, pedido),
            ),
          ),
        ),
      ),
    );
  }

  // ---------------------------------------------------------------------
  // Antes de enviar
  // ---------------------------------------------------------------------

  Widget _formulario(BuildContext context) {
    final tema = Theme.of(context);
    final producto = widget.producto;
    final variante = widget.variante;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Card(
          margin: EdgeInsets.zero,
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(producto.nombre, style: tema.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.bold)),
                const SizedBox(height: 4),
                Text(
                  'Modelo ${producto.modelo} · Talla ${variante.talla} · ${variante.colorParaMostrar}',
                  style: tema.textTheme.bodyMedium?.copyWith(color: tema.colorScheme.onSurfaceVariant),
                ),
                const Divider(height: 28),
                Row(
                  children: [
                    const Expanded(child: Text('Cantidad', style: TextStyle(fontWeight: FontWeight.w600))),
                    IconButton.outlined(
                      tooltip: 'Un par menos',
                      onPressed: _enviando || _cantidad <= 1 ? null : () => setState(() => _cantidad--),
                      icon: const Icon(Icons.remove),
                    ),
                    SizedBox(
                      width: 44,
                      child: Text(
                        '$_cantidad',
                        textAlign: TextAlign.center,
                        style: tema.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.bold),
                      ),
                    ),
                    IconButton.outlined(
                      tooltip: 'Un par más',
                      onPressed: _enviando || _cantidad >= _cantidadMaxima ? null : () => setState(() => _cantidad++),
                      icon: const Icon(Icons.add),
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                _Fila(
                  etiqueta: _esRevendedor ? 'Precio mayorista' : 'Precio',
                  valor: formatoPrecio(_precioUnitarioEstimado),
                ),
                _Fila(
                  etiqueta: 'Total estimado',
                  valor: formatoPrecio(_precioUnitarioEstimado * _cantidad),
                  destacado: true,
                ),
                const SizedBox(height: 4),
                Text(
                  'El total final lo calcula la distribuidora al recibir tu pedido.',
                  style: tema.textTheme.bodySmall?.copyWith(color: tema.colorScheme.onSurfaceVariant),
                ),
              ],
            ),
          ),
        ),
        const SizedBox(height: 16),
        SelectorVale(
          api: _api,
          habilitado: !_enviando,
          alCambiar: (vale) => _valeSeleccionado = vale,
        ),
        const SizedBox(height: 16),
        _Nota(
          icono: Icons.storefront_outlined,
          texto: _esRevendedor
              ? 'Los pagos se registran en mostrador. Al enviar tu pedido te diremos cuánto queda pendiente.'
              : 'El anticipo se paga en mostrador. Al enviar tu pedido te diremos cuánto es.',
        ),
        if (_error != null) ...[
          const SizedBox(height: 16),
          AvisoError(mensaje: _error!),
        ],
        const SizedBox(height: 24),
        FilledButton.icon(
          onPressed: _enviando ? null : _enviar,
          style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
          icon: _enviando
              ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2))
              : const Icon(Icons.send_rounded),
          label: Text(_enviando ? 'Enviando pedido...' : 'Enviar pedido'),
        ),
      ],
    );
  }

  // ---------------------------------------------------------------------
  // Después de enviar: los números que calculó el servidor
  // ---------------------------------------------------------------------

  Widget _enviado(BuildContext context, PedidoResumen pedido) {
    final tema = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Icon(Icons.check_circle_outline, size: 72, color: Colors.green.shade600),
        const SizedBox(height: 12),
        Text('¡Pedido enviado!', textAlign: TextAlign.center, style: tema.textTheme.headlineSmall),
        const SizedBox(height: 4),
        Text(
          'Folio ${pedido.folio}',
          textAlign: TextAlign.center,
          style: tema.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w600),
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
                if (pedido.pagado - pedido.pagadoConVales > 0.009)
                  _Fila(etiqueta: 'Otros pagos', valor: '-${formatoPrecio(pedido.pagado - pedido.pagadoConVales)}'),
                const Divider(height: 24),
                if (_esRevendedor)
                  _Fila(etiqueta: 'Pendiente por pagar', valor: formatoPrecio(pedido.saldo), destacado: true)
                else ...[
                  _Fila(
                    etiqueta: 'Anticipo a pagar en mostrador',
                    valor: formatoPrecio(pedido.anticipoPendiente),
                    destacado: true,
                  ),
                  _Fila(etiqueta: 'Saldo total pendiente', valor: formatoPrecio(pedido.saldo)),
                ],
              ],
            ),
          ),
        ),
        const SizedBox(height: 16),
        _Nota(
          icono: Icons.storefront_outlined,
          texto: _esRevendedor
              ? 'Paga en mostrador; ahí registran tu pago. Te avisaremos cuando tu pedido llegue.'
              : 'Paga tu anticipo en mostrador; ahí lo registran. Te avisaremos cuando tu pedido llegue.',
        ),
        if (_avisoVale != null) ...[
          const SizedBox(height: 12),
          AvisoError(mensaje: 'Tu pedido sí se envió, pero no se pudo aplicar el vale: $_avisoVale'),
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

class _Nota extends StatelessWidget {
  const _Nota({required this.icono, required this.texto});

  final IconData icono;
  final String texto;

  @override
  Widget build(BuildContext context) {
    final colores = Theme.of(context).colorScheme;

    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: colores.primaryContainer.withValues(alpha: 0.4),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icono, color: colores.primary),
          const SizedBox(width: 12),
          Expanded(child: Text(texto)),
        ],
      ),
    );
  }
}
