import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../models/pedido_resumen.dart';
import '../models/producto_catalogo.dart';
import '../models/vale.dart';
import '../providers/auth_provider.dart';
import '../services/api_service.dart';
import '../services/pedido_service.dart';
import '../services/vale_service.dart';
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
  late final _valeService = ValeService(api: _api);
  late final _pedidos = PedidoService(_api);

  static const _cantidadMaxima = 20;

  int _cantidad = 1;
  bool _cargandoVales = true;
  List<Vale> _valesVigentes = [];
  Vale? _valeSeleccionado;

  bool _enviando = false;
  String? _error;

  /// El pedido ya enviado. Mientras sea null se ve el formulario.
  PedidoResumen? _pedido;
  double? _valeAplicado;
  String? _avisoVale;

  bool get _esRevendedor => widget.producto.precioMayorista != null;

  /// Solo para orientar antes de enviar. El total de verdad lo calcula el
  /// servidor según el tipo de pedido (Kevin, #166).
  double get _precioUnitarioEstimado =>
      _esRevendedor ? widget.producto.precioMayorista! : widget.producto.precioMinoristaSugerido;

  @override
  void initState() {
    super.initState();
    _cargarValesVigentes();
  }

  Future<void> _cargarValesVigentes() async {
    try {
      final vales = await _valeService.listarValesVigentes();
      if (!mounted) return;

      final ahora = DateTime.now();
      setState(() {
        // Solo los que el servidor aceptaría: activos, con saldo y sin vencer.
        _valesVigentes = vales
            .where((v) =>
                v.estado == 'activo' &&
                v.saldoActual > 0 &&
                (v.fechaVencimiento == null || v.fechaVencimiento!.isAfter(ahora)))
            .toList();
        _cargandoVales = false;
      });
    } on ApiException {
      // Sin vales no se detiene el pedido: solo no se ofrecen.
      if (mounted) setState(() => _cargandoVales = false);
    }
  }

  Future<void> _enviar() async {
    setState(() {
      _enviando = true;
      _error = null;
    });

    try {
      var pedido = await _pedidos.crearYEnviar(
        tipo: _esRevendedor ? 'revendedor' : 'cliente_directo',
        lineas: [
          (
            productoCampanaId: widget.producto.id,
            varianteId: widget.variante.varianteId,
            cantidad: _cantidad,
          ),
        ],
      );

      double? aplicado;
      String? aviso;

      final vale = _valeSeleccionado;
      if (vale != null) {
        // Se aplica al pedido REAL, y como mucho lo que se debe: hoy el
        // servidor solo limita al saldo del vale, así que mandar todo el saldo
        // gastaría el vale completo aunque el pedido costara menos.
        final monto = math.min(vale.saldoActual, pedido.saldo);

        if (monto > 0) {
          try {
            await _valeService.aplicarVale(valeId: vale.id, monto: monto, pedidoId: pedido.id);
            aplicado = monto;
            pedido = await _pedidos.ver(pedido.id);
          } on ApiException catch (e) {
            // El pedido ya se envió: no se pierde, solo se avisa del vale.
            aviso = e.errores.values.isNotEmpty ? e.errores.values.first.first : e.mensaje;
          }
        }
      }

      if (!mounted) return;
      setState(() {
        _pedido = pedido;
        _valeAplicado = aplicado;
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
        _seccionVale(context),
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

  Widget _seccionVale(BuildContext context) {
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
            if (_cargandoVales)
              const LinearProgressIndicator()
            else if (_valesVigentes.isEmpty)
              const Text('No tienes vales vigentes.', style: TextStyle(color: Colors.grey))
            else
              Row(
                children: [
                  Expanded(
                    // `value` (y no `initialValue`) a propósito: "Quitar vale"
                    // limpia la selección desde el código.
                    child: DropdownButtonFormField<Vale?>(
                      // ignore: deprecated_member_use
                      value: _valeSeleccionado,
                      isExpanded: true,
                      decoration: const InputDecoration(
                        labelText: 'Vale (opcional)',
                        border: OutlineInputBorder(),
                      ),
                      items: [
                        const DropdownMenuItem<Vale?>(value: null, child: Text('Sin vale')),
                        for (final vale in _valesVigentes)
                          DropdownMenuItem<Vale?>(
                            value: vale,
                            child: Text(
                              '${vale.folio} · disponible ${formatoPrecio(vale.saldoActual)}',
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                      ],
                      onChanged: _enviando ? null : (vale) => setState(() => _valeSeleccionado = vale),
                    ),
                  ),
                  if (_valeSeleccionado != null) ...[
                    const SizedBox(width: 8),
                    IconButton(
                      icon: Icon(Icons.clear, color: tema.colorScheme.error),
                      tooltip: 'Quitar vale',
                      onPressed: _enviando ? null : () => setState(() => _valeSeleccionado = null),
                    ),
                  ],
                ],
              ),
          ],
        ),
      ),
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
                if (_valeAplicado != null)
                  _Fila(etiqueta: 'Vale aplicado', valor: '-${formatoPrecio(_valeAplicado!)}'),
                if (pedido.pagado > 0) _Fila(etiqueta: 'Pagado', valor: formatoPrecio(pedido.pagado)),
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
