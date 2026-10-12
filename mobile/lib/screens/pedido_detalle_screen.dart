import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../models/estado_pedido.dart';
import '../models/pedido_resumen.dart';
import '../providers/auth_provider.dart';
import '../services/api_service.dart';
import '../services/cambio_service.dart';
import '../services/pedido_service.dart';
import '../tema/fp_colores.dart';
import 'producto_detalle_screen.dart';
import '../widgets/botones_pago_mercado_pago.dart';
import '../widgets/bajo_pedido.dart';
import '../widgets/fp_componentes.dart';

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
  late final _cambios = CambioService(context.read<AuthProvider>().api);

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

  /// TG-254: pide el motivo y envía la solicitud de cambio de esa línea.
  Future<void> _solicitarCambio(LineaPedido linea) async {
    final motivo = await showDialog<String>(
      context: context,
      builder: (_) => _DialogoCambio(producto: linea.productoNombre),
    );
    if (motivo == null || !mounted) return;

    try {
      final mensaje = await _cambios.solicitar(pedidoDetalleId: linea.id!, motivo: motivo);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(mensaje)));
      await _cargar();
    } on ApiException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.mensaje)));
    }
  }

  @override
  Widget build(BuildContext context) {
    final pedido = _pedido;

    return Scaffold(
      appBar: AppBar(title: Text(pedido?.folio ?? 'Pedido'), bottom: const FpBordeMarca()),
      body: SafeArea(
        child: _cargando
            ? const Center(child: CircularProgressIndicator())
            : pedido == null
            ? Center(
                child: FpEstadoVacio(
                  error: true,
                  icono: Icons.receipt_long_outlined,
                  titulo: _error ?? 'No se pudo cargar el pedido.',
                  accion: FilledButton.icon(
                    onPressed: () {
                      setState(() => _cargando = true);
                      _cargar();
                    },
                    icon: const Icon(Icons.refresh),
                    label: const Text('Reintentar'),
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
        FpTarjeta(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  const FpIconoCuadro(icono: Icons.local_shipping_outlined, tamano: 36),
                  const SizedBox(width: 12),
                  const Expanded(
                    child: Text(
                      'Estado',
                      style: TextStyle(color: FpColores.sidebar, fontSize: 16, fontWeight: FontWeight.w600),
                    ),
                  ),
                  EtiquetaEstadoPedido(estado: pedido.estado),
                ],
              ),
              if (estado.descripcion != null) ...[
                const SizedBox(height: 8),
                Text(estado.descripcion!, style: tema.textTheme.bodyLarge),
              ],
              // TG-125: la fecha límite para recogerlo.
              if (pedido.avisoRecoleccion != null) ...[
                const SizedBox(height: 8),
                Row(
                  children: [
                    const Icon(Icons.event_outlined, size: 18, color: FpColores.insigniaAvisoTexto),
                    const SizedBox(width: 6),
                    Expanded(
                      child: Text(
                        pedido.avisoRecoleccion!,
                        style: tema.textTheme.bodyLarge?.copyWith(
                          color: FpColores.insigniaAvisoTexto,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ),
                  ],
                ),
              ],
              if (pedido.fecha != null) ...[
                const SizedBox(height: 8),
                Text(
                  'Enviado el ${formatoFecha(pedido.fecha!)}',
                  style: tema.textTheme.bodySmall?.copyWith(color: FpColores.textoTenue),
                ),
              ],
            ],
          ),
        ),
        const SizedBox(height: 20),

        // Productos
        const FpTituloSeccion('Productos'),
        for (final linea in pedido.lineas)
          Card(
            margin: const EdgeInsets.only(bottom: 8),
            child: ListTile(
              leading: const FpIconoCuadro(icono: Icons.inventory_2_outlined, tamano: 36),
              title: Text(linea.productoNombre, style: const TextStyle(fontWeight: FontWeight.w600)),
              subtitle: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Modelo ${linea.modelo} · Talla ${linea.talla} · ${linea.color}\n'
                    '${linea.cantidad} × ${formatoPrecio(linea.precioUnitario)}',
                  ),
                  // TG-266
                  if (linea.bajoPedido) ...[
                    const SizedBox(height: 4),
                    const InsigniaBajoPedido(),
                  ],
                ],
              ),
              isThreeLine: true,
              trailing: Text(
                formatoPrecio(linea.subtotal),
                style: const TextStyle(fontWeight: FontWeight.w600),
              ),
            ),
          ),
        if (pedido.lineas.any((l) => l.bajoPedido))
          const Padding(
            padding: EdgeInsets.only(bottom: 8),
            child: AvisoBajoPedido(texto: textoTallasBajoPedido),
          ),
        // TG-254: cambio de producto dentro del plazo (lo decide el servidor).
        if (pedido.lineas.any((l) => l.cambio != null && (l.cambio!.puedeSolicitar || l.cambio!.tieneSolicitud))) ...[
          const SizedBox(height: 4),
          const FpTituloSeccion('Cambios'),
          for (final linea in pedido.lineas)
            if (linea.cambio != null && linea.id != null && (linea.cambio!.puedeSolicitar || linea.cambio!.tieneSolicitud))
              Card(
                margin: const EdgeInsets.only(bottom: 8),
                child: ListTile(
                  leading: const FpIconoCuadro(icono: Icons.swap_horiz, tamano: 36),
                  title: Text(linea.productoNombre),
                  subtitle: Text(
                    linea.cambio!.tieneSolicitud
                        ? linea.cambio!.estadoParaMostrar
                        : 'Puedes pedir cambio hasta el ${linea.cambio!.fechaLimiteTexto ?? ''}',
                  ),
                  trailing: linea.cambio!.puedeSolicitar
                      ? TextButton(onPressed: () => _solicitarCambio(linea), child: const Text('Solicitar cambio'))
                      : null,
                ),
              ),
          Text(
            'El cambio se entrega como vale; no hay devoluciones en efectivo.',
            style: tema.textTheme.bodySmall?.copyWith(color: FpColores.textoTenue),
          ),
        ],
        const SizedBox(height: 12),

        // Pagos
        FpTarjeta(
          child: Column(
            children: [
              _Fila(etiqueta: 'Total del pedido', valor: formatoPrecio(pedido.total)),
              if (pedido.pagadoConVales > 0)
                _Fila(etiqueta: 'Pagado con vale', valor: '-${formatoPrecio(pedido.pagadoConVales)}'),
              if (otrosPagos > 0.009)
                _Fila(etiqueta: 'Otros pagos', valor: '-${formatoPrecio(otrosPagos)}'),
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
        // TG-268: pagar o verificar con Mercado Pago, según el servidor.
        if (pedido.pagoMercadoPagoDisponible != null || pedido.pagoMercadoPagoPorVerificar != null) ...[
          const SizedBox(height: 12),
          BotonesPagoMercadoPago(pedido: pedido, alTerminar: _cargar),
        ],
        if (pedido.saldo > 0) ...[
          const SizedBox(height: 12),
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: FpColores.primario.withValues(alpha: 0.06),
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: FpColores.primario.withValues(alpha: 0.15)),
            ),
            child: Row(
              children: [
                const Icon(Icons.storefront_outlined, size: 20, color: FpColores.primario),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    pedido.pagoMercadoPagoDisponible != null
                        ? 'Puedes pagar con Mercado Pago o en mostrador.'
                        : 'Los pagos se hacen y se registran en mostrador.',
                  ),
                ),
              ],
            ),
          ),
        ],

        if (pedido.pagos.isNotEmpty) ...[
          const SizedBox(height: 20),
          const FpTituloSeccion('Pagos registrados'),
          for (final pago in pedido.pagos)
            Card(
              margin: const EdgeInsets.only(bottom: 8),
              child: ListTile(
                leading: const FpIconoCuadro(
                  icono: Icons.payments_outlined,
                  tamano: 36,
                  fondo: FpColores.insigniaExitoFondo,
                  color: FpColores.insigniaExitoTexto,
                ),
                title: Text('${pago.tipoParaMostrar} · ${formatoPrecio(pago.monto)}'),
                subtitle: Text(
                  [
                    pago.folio,
                    if (pago.metodo.isNotEmpty) pago.metodoParaMostrar,
                    if (pago.esPendiente) 'Por confirmar',
                    if (pago.fecha != null) formatoFecha(pago.fecha!, conHora: false),
                  ].join(' · '),
                ),
              ),
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


/// TG-254: pide el motivo del cambio (obligatorio, hasta 300 caracteres).
class _DialogoCambio extends StatefulWidget {
  const _DialogoCambio({required this.producto});

  final String producto;

  @override
  State<_DialogoCambio> createState() => _DialogoCambioState();
}

class _DialogoCambioState extends State<_DialogoCambio> {
  final _motivo = TextEditingController();
  String? _error;

  @override
  void dispose() {
    _motivo.dispose();
    super.dispose();
  }

  void _enviar() {
    final texto = _motivo.text.trim();
    if (texto.isEmpty) {
      setState(() => _error = 'Escribe el motivo del cambio.');
      return;
    }
    Navigator.of(context).pop(texto);
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('Solicitar cambio'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(widget.producto, style: const TextStyle(fontWeight: FontWeight.w600)),
          const SizedBox(height: 12),
          TextField(
            key: const Key('motivo_cambio'),
            controller: _motivo,
            maxLength: 300,
            maxLines: 3,
            decoration: InputDecoration(labelText: 'Motivo', errorText: _error),
          ),
          const Text('Si se autoriza, recibirás un vale. No hay devoluciones en efectivo.'),
        ],
      ),
      actions: [
        TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text('Cancelar')),
        FilledButton(onPressed: _enviar, child: const Text('Enviar')),
      ],
    );
  }
}
