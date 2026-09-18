import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../models/estado_pedido.dart';
import '../models/pedido_resumen.dart';
import '../providers/auth_provider.dart';
import '../services/api_service.dart';
import '../services/pedido_service.dart';
import 'pedido_detalle_screen.dart';
import 'producto_detalle_screen.dart';

/// "Mis pedidos" (TG-165): los pedidos propios con su estado, para que el
/// cliente o revendedor pueda "ver estatus" desde la app (plan del sprint,
/// sección 0.1). El servidor solo regresa los de quien inició sesión.
class MisPedidosScreen extends StatefulWidget {
  const MisPedidosScreen({super.key});

  @override
  State<MisPedidosScreen> createState() => _MisPedidosScreenState();
}

class _MisPedidosScreenState extends State<MisPedidosScreen> {
  late final _pedidos = PedidoService(context.read<AuthProvider>().api);

  List<PedidoResumen>? _lista;
  bool _cargando = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _cargar();
  }

  Future<void> _cargar() async {
    try {
      final lista = await _pedidos.listar();
      if (!mounted) return;
      setState(() {
        _lista = lista;
        _error = null;
        _cargando = false;
      });
    } on ApiException catch (e) {
      // Un 401 lo atiende AuthProvider (regresa al login).
      if (!mounted) return;
      setState(() {
        _error = e.mensaje;
        _cargando = false;
      });
    }
  }

  void _reintentar() {
    setState(() {
      _cargando = true;
      _error = null;
    });
    _cargar();
  }

  Future<void> _abrir(PedidoResumen pedido) async {
    await Navigator.of(context).push(
      MaterialPageRoute<void>(builder: (_) => PedidoDetalleScreen(pedidoId: pedido.id)),
    );
    // Al regresar se actualiza: el estado o el saldo pudieron cambiar.
    if (mounted) _cargar();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Mis pedidos')),
      body: SafeArea(child: _contenido(context)),
    );
  }

  Widget _contenido(BuildContext context) {
    final tema = Theme.of(context);

    if (_cargando) return const Center(child: CircularProgressIndicator());

    final lista = _lista;
    if (lista == null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(Icons.cloud_off_outlined, size: 64, color: tema.colorScheme.error),
              const SizedBox(height: 16),
              Text(_error ?? 'No se pudieron cargar tus pedidos.', textAlign: TextAlign.center),
              const SizedBox(height: 16),
              FilledButton.icon(
                onPressed: _reintentar,
                icon: const Icon(Icons.refresh),
                label: const Text('Reintentar'),
              ),
            ],
          ),
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: _cargar,
      child: lista.isEmpty
          ? ListView(
              // ListView para que se pueda deslizar y recargar aunque esté vacío.
              children: [
                const SizedBox(height: 120),
                Icon(Icons.receipt_long_outlined, size: 64, color: tema.colorScheme.onSurfaceVariant),
                const SizedBox(height: 16),
                const Text('Todavía no tienes pedidos.', textAlign: TextAlign.center),
                const SizedBox(height: 4),
                Text(
                  'Cuando envíes uno desde el catálogo, aparecerá aquí con su estado.',
                  textAlign: TextAlign.center,
                  style: tema.textTheme.bodySmall?.copyWith(color: tema.colorScheme.onSurfaceVariant),
                ),
              ],
            )
          : ListView.separated(
              padding: const EdgeInsets.all(16),
              itemCount: lista.length,
              separatorBuilder: (_, _) => const SizedBox(height: 12),
              itemBuilder: (_, i) => _TarjetaPedido(pedido: lista[i], alTocar: () => _abrir(lista[i])),
            ),
    );
  }
}

class _TarjetaPedido extends StatelessWidget {
  const _TarjetaPedido({required this.pedido, required this.alTocar});

  final PedidoResumen pedido;
  final VoidCallback alTocar;

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);
    final debe = pedido.saldo > 0;

    return Card(
      margin: EdgeInsets.zero,
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: alTocar,
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      pedido.folio,
                      style: tema.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w600),
                    ),
                  ),
                  EtiquetaEstadoPedido(estado: pedido.estado),
                ],
              ),
              if (pedido.fecha != null) ...[
                const SizedBox(height: 2),
                Text(
                  formatoFecha(pedido.fecha!),
                  style: tema.textTheme.bodySmall?.copyWith(color: tema.colorScheme.onSurfaceVariant),
                ),
              ],
              const SizedBox(height: 12),
              Row(
                children: [
                  Expanded(child: _Dato(etiqueta: 'Total', valor: formatoPrecio(pedido.total))),
                  Expanded(
                    child: _Dato(
                      etiqueta: debe ? 'Saldo pendiente' : 'Saldo',
                      valor: formatoPrecio(pedido.saldo),
                      resaltar: debe,
                    ),
                  ),
                  const Icon(Icons.chevron_right),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Dato extends StatelessWidget {
  const _Dato({required this.etiqueta, required this.valor, this.resaltar = false});

  final String etiqueta;
  final String valor;
  final bool resaltar;

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(etiqueta, style: tema.textTheme.labelSmall?.copyWith(color: tema.colorScheme.onSurfaceVariant)),
        Text(
          valor,
          style: tema.textTheme.titleSmall?.copyWith(
            fontWeight: FontWeight.w600,
            color: resaltar ? tema.colorScheme.error : null,
          ),
        ),
      ],
    );
  }
}
