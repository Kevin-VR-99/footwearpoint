import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../models/estado_pedido.dart';
import '../models/pedido_resumen.dart';
import '../providers/auth_provider.dart';
import '../services/api_service.dart';
import '../services/pedido_service.dart';
import '../tema/fp_colores.dart';
import '../widgets/fp_componentes.dart';
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

  /// Auditoría 21-oct-2026: la API pagina. Se piden 25 y, al llegar al final
  /// de la lista, la siguiente página.
  static const _porPagina = 25;
  int _pagina = 1;
  bool _hayMas = false;
  bool _cargandoMas = false;

  /// TG-281: filtra por folio o por estado. Quien tiene muchos pedidos busca
  /// uno concreto, y antes habia que recorrerlos todos.
  String _busqueda = '';
  bool _cargando = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _cargar();
  }

  Future<void> _cargar() async {
    try {
      final pagina = await _pedidos.listarPagina(pagina: 1, porPagina: _porPagina);
      if (!mounted) return;
      setState(() {
        _lista = pagina.elementos;
        _pagina = pagina.paginaActual;
        _hayMas = pagina.hayMas;
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

  Future<void> _cargarMas() async {
    if (!_hayMas || _cargandoMas) return;
    setState(() => _cargandoMas = true);

    try {
      final pagina = await _pedidos.listarPagina(pagina: _pagina + 1, porPagina: _porPagina);
      if (!mounted) return;
      final vistos = {for (final p in _lista ?? const <PedidoResumen>[]) p.id};
      setState(() {
        _lista = [...?_lista, ...pagina.elementos.where((p) => !vistos.contains(p.id))];
        _pagina = pagina.paginaActual;
        _hayMas = pagina.hayMas;
        _cargandoMas = false;
      });
    } on ApiException {
      // Se queda el botón "Ver más" para intentarlo otra vez.
      if (mounted) setState(() => _cargandoMas = false);
    }
  }

  bool _alDeslizar(ScrollNotification aviso) {
    if (aviso.metrics.extentAfter < 400) _cargarMas();
    return false;
  }

  Widget _pie() {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 8),
      child: Center(
        child: _cargandoMas
            ? const CircularProgressIndicator()
            : TextButton(onPressed: _cargarMas, child: const Text('Ver más pedidos')),
      ),
    );
  }

  void _reintentar() {
    setState(() {
      _cargando = true;
      _error = null;
    });
    _cargar();
  }

  Future<void> _abrir(PedidoResumen pedido) async {
    await Navigator.of(context)
        .push(MaterialPageRoute<void>(builder: (_) => PedidoDetalleScreen(pedidoId: pedido.id)));
    // Al regresar se actualiza: el estado o el saldo pudieron cambiar.
    if (mounted) _cargar();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Mis pedidos'), bottom: const FpBordeMarca()),
      body: SafeArea(child: _contenido(context)),
    );
  }

  Widget _contenido(BuildContext context) {
    if (_cargando) return const Center(child: CircularProgressIndicator());

    final lista = _lista;
    if (lista == null) {
      return Center(
        child: FpEstadoVacio(
          error: true,
          icono: Icons.cloud_off_outlined,
          titulo: _error ?? 'No se pudieron cargar tus pedidos.',
          accion: FilledButton.icon(
            onPressed: _reintentar,
            icon: const Icon(Icons.refresh),
            label: const Text('Reintentar'),
          ),
        ),
      );
    }

    if (lista.isEmpty) {
      return RefreshIndicator(
        onRefresh: _cargar,
        child: ListView(
          // ListView para que se pueda deslizar y recargar aunque esté vacío.
          children: const [
            SizedBox(height: 80),
            FpEstadoVacio(
              icono: Icons.receipt_long_outlined,
              titulo: 'Todavía no tienes pedidos.',
              texto: 'Cuando envíes uno desde el catálogo, aparecerá aquí con su estado.',
            ),
          ],
        ),
      );
    }

    final encontrados = filtrarPedidos(lista, _busqueda);

    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
          child: FpBuscador(
            texto: 'Buscar por folio o estado',
            alCambiar: (valor) => setState(() => _busqueda = valor),
          ),
        ),
        Expanded(
          child: RefreshIndicator(
            onRefresh: _cargar,
            child: encontrados.isEmpty
                ? ListView(children: [FpSinResultados(busqueda: _busqueda, que: 'pedidos')])
                : NotificationListener<ScrollNotification>(
                    onNotification: _alDeslizar,
                    child: ListView.separated(
                      padding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
                      itemCount: encontrados.length + (_hayMas ? 1 : 0),
                      separatorBuilder: (_, _) => const SizedBox(height: 12),
                      itemBuilder: (_, i) => i == encontrados.length
                          ? _pie()
                          : _TarjetaPedido(pedido: encontrados[i], alTocar: () => _abrir(encontrados[i])),
                    ),
                  ),
          ),
        ),
      ],
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

    return FpTarjeta(
      alTocar: alTocar,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const FpIconoCuadro(icono: Icons.receipt_long_outlined, tamano: 36),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      pedido.folio,
                      style: tema.textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w600,
                        color: FpColores.sidebar,
                      ),
                    ),
                    if (pedido.fecha != null)
                      Text(
                        formatoFecha(pedido.fecha!),
                        style: tema.textTheme.bodySmall?.copyWith(color: FpColores.textoTenue),
                      ),
                  ],
                ),
              ),
              EtiquetaEstadoPedido(estado: pedido.estado),
            ],
          ),
          const Divider(height: 24),
          Row(
            children: [
              Expanded(
                child: _Dato(etiqueta: 'Total', valor: formatoPrecio(pedido.total)),
              ),
              Expanded(
                child: _Dato(
                  etiqueta: debe ? 'Saldo pendiente' : 'Saldo',
                  valor: formatoPrecio(pedido.saldo),
                  resaltar: debe,
                ),
              ),
              const Icon(Icons.chevron_right_rounded, color: FpColores.bordeFuerte),
            ],
          ),
        ],
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

/// Filtra por folio o por el estado tal como se lee en pantalla.
///
/// Se busca por el estado visible ("Listo para entrega") y no por el del
/// servidor ("listo_entrega"): la persona busca lo que ve.
List<PedidoResumen> filtrarPedidos(List<PedidoResumen> pedidos, String busqueda) {
  final palabras = busqueda.toLowerCase().trim().split(RegExp(r'\s+'))
      ..removeWhere((p) => p.isEmpty);

  if (palabras.isEmpty) return pedidos;

  return pedidos.where((pedido) {
    final texto = '${pedido.folio} ${EstadoPedido.de(pedido.estado).etiqueta}'.toLowerCase();

    return palabras.every(texto.contains);
  }).toList();
}
