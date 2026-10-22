import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../models/filtro_catalogo.dart';
import '../models/producto_catalogo.dart';
import '../providers/auth_provider.dart';
import '../services/api_service.dart';
import '../services/catalogo_service.dart';
import 'login_screen.dart';
import 'productos_linea_screen.dart';
import '../widgets/fila_producto.dart';
import '../tema/fp_colores.dart';
import '../widgets/fp_componentes.dart';

/// Primera pantalla del catálogo (E4-05): las líneas con productos.
///
/// Flujo del plan del sprint: línea -> productos -> variante (talla/color).
/// El catálogo se pide una sola vez al entrar y cada pantalla le pasa los
/// datos a la siguiente. Deslizar hacia abajo lo vuelve a pedir.
class CatalogoScreen extends StatefulWidget {
  const CatalogoScreen({super.key});

  @override
  State<CatalogoScreen> createState() => _CatalogoScreenState();
}

class _CatalogoScreenState extends State<CatalogoScreen> {
  List<LineaCatalogo>? _lineas;

  /// Todos los productos, sin agrupar: es sobre esto que busca el buscador.
  List<ProductoCatalogo> _productos = const [];

  /// TG-281: mientras hay algo escrito, en vez de las lineas se enseñan los
  /// productos que coinciden, de TODAS las lineas. Buscar entre dos lineas no
  /// le sirve a nadie; buscar un modelo, si.
  String _busqueda = '';
  String? _marca;
  String? _categoria;
  String? _talla;
  String? _disponibilidad;

  bool _cargando = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _cargar();
  }

  void _reintentar() {
    setState(() {
      _cargando = true;
      _error = null;
    });
    _cargar();
  }

  /// La primera vez se llama desde initState, donde todavía no se puede usar
  /// setState: por eso [_cargando] ya empieza en true.
  Future<void> _cargar({bool forzar = false}) async {
    final servicio = CatalogoService(context.read<AuthProvider>().api);

    try {
      final productos = await servicio.obtener(forzar: forzar);
      if (!mounted) return;

      setState(() {
        _productos = productos;
        _lineas = LineaCatalogo.agrupar(productos);
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

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Catálogo'), bottom: const FpBordeMarca()),
      body: SafeArea(child: _contenido(context)),
    );
  }

  Widget _contenido(BuildContext context) {
    if (_cargando) {
      return const Center(child: CircularProgressIndicator());
    }

    // Sin datos y con error: pantalla de error. Si ya había datos y falló una
    // recarga, se siguen mostrando los datos y el error sale en un aviso.
    if (_lineas == null) {
      return _Aviso(
        icono: Icons.cloud_off_outlined,
        mensaje: _error ?? 'No se pudo cargar el catálogo.',
        esError: true,
        alReintentar: _reintentar,
      );
    }

    return RefreshIndicator(
      onRefresh: () => _cargar(forzar: true),
      child: _lineas!.isEmpty
          ? ListView(
              // ListView para que se pueda deslizar y recargar aunque esté vacío.
              children: const [
                SizedBox(height: 120),
                _Aviso(
                  icono: Icons.inventory_2_outlined,
                  mensaje: 'Por ahora no hay productos publicados en el catálogo.',
                ),
              ],
            )
          : _lista(context),
    );
  }

  FiltroCatalogo get _filtro => FiltroCatalogo(
    marca: _marca,
    categoria: _categoria,
    talla: _talla,
    disponibilidad: _disponibilidad,
  );

  List<ProductoCatalogo> get _visibles {
    final filtrados = aplicarFiltroCatalogo(_productos, _filtro);
    _lineas = LineaCatalogo.agrupar(filtrados);
    return filtrados;
  }

  Widget _lista(BuildContext context) {
    final visibles = _visibles;
    final buscando = _busqueda.trim().isNotEmpty;
    final encontrados = buscando ? filtrarProductos(visibles, _busqueda) : const <ProductoCatalogo>[];

    // TG-281: el buscador va fijo arriba y no dentro del scroll. Si se
    // fuera con la lista, para buscar otra cosa habria que subir primero.
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
          child: FpBuscador(
            texto: 'Buscar un producto en todo el catálogo',
            alCambiar: (valor) => setState(() => _busqueda = valor),
          ),
        ),
        _filtros(),
        Expanded(child: _resultados(buscando, encontrados, visibles)),
      ],
    );
  }

  Widget _filtros() {
    final marcas = opcionesDe(_productos.map((p) => p.marca?.nombre));
    final categorias = opcionesDe(_productos.map((p) => p.categoria?.nombre));
    final tallas = opcionesDe(_productos.expand((p) => p.variantes.map((v) => v.talla)));

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 4),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Wrap(
            spacing: 8,
            children: [
              ChoiceChip(
                label: const Text('Todo'),
                selected: _disponibilidad == null,
                onSelected: (_) => setState(() => _disponibilidad = null),
              ),
              ChoiceChip(
                label: const Text('Disponible'),
                selected: _disponibilidad == 'disponible',
                onSelected: (_) => setState(() => _disponibilidad = 'disponible'),
              ),
              ChoiceChip(
                label: const Text('Bajo pedido'),
                selected: _disponibilidad == 'bajo_pedido',
                onSelected: (_) => setState(() => _disponibilidad = 'bajo_pedido'),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              _desplegable('Marca', _marca, marcas, (valor) => setState(() => _marca = valor)),
              _desplegable('Categoría', _categoria, categorias, (valor) => setState(() => _categoria = valor)),
              _desplegable('Talla', _talla, tallas, (valor) => setState(() => _talla = valor)),
            ],
          ),
        ],
      ),
    );
  }

  Widget _desplegable(String etiqueta, String? actual, List<String> opciones, ValueChanged<String?> alCambiar) {
    return SizedBox(
      width: 150,
      child: DropdownButton<String?>(
        isExpanded: true,
        value: actual != null && opciones.contains(actual) ? actual : null,
        hint: Text(etiqueta),
        items: [
          DropdownMenuItem<String?>(value: null, child: Text(etiqueta, overflow: TextOverflow.ellipsis)),
          for (final opcion in opciones)
            DropdownMenuItem<String?>(
              value: opcion,
              child: Text(opcion, overflow: TextOverflow.ellipsis),
            ),
        ],
        onChanged: alCambiar,
      ),
    );
  }

  Widget _resultados(bool buscando, List<ProductoCatalogo> encontrados, List<ProductoCatalogo> visibles) {
    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 6, 16, 16),
      children: [
        if (_error != null) ...[
          AvisoError(mensaje: _error!),
          const SizedBox(height: 12),
        ],
        if (buscando) ...[
          if (encontrados.isEmpty)
            FpSinResultados(busqueda: _busqueda, que: 'productos')
          else ...[
            FpTituloSeccion(
              encontrados.length == 1 ? '1 producto' : '${encontrados.length} productos',
            ),
            for (final producto in encontrados) ...[
              FilaProducto(producto: producto),
              const SizedBox(height: 10),
            ],
          ],
        ] else if (visibles.isEmpty) ...[
          const FpEstadoVacio(
            icono: Icons.filter_alt_off_outlined,
            titulo: 'Ningún producto coincide con esos filtros.',
          ),
        ] else ...[
          FpEncabezado(
            etiqueta: 'Catálogo',
            titulo: 'Elige una línea',
            subtitulo: _lineas!.length == 1
                ? '1 línea con productos publicados'
                : '${_lineas!.length} líneas con productos publicados',
          ),
          const SizedBox(height: 12),
          for (final linea in _lineas!) _TarjetaLinea(linea: linea),
        ],
      ],
    );
  }
}

class _TarjetaLinea extends StatelessWidget {
  const _TarjetaLinea({required this.linea});

  final LineaCatalogo linea;

  @override
  Widget build(BuildContext context) {
    final cantidad = linea.productos.length;
    return Card(
      margin: const EdgeInsets.symmetric(vertical: 5),
      child: ListTile(
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        leading: const FpIconoCuadro(icono: Icons.style_outlined),
        title: Text(
          linea.nombre,
          style: const TextStyle(color: FpColores.sidebar, fontWeight: FontWeight.w600),
        ),
        subtitle: Text(cantidad == 1 ? '1 producto' : '$cantidad productos'),
        trailing: const Icon(Icons.chevron_right_rounded, color: FpColores.bordeFuerte),
        onTap: () => Navigator.of(context).push(
          MaterialPageRoute<void>(builder: (_) => ProductosLineaScreen(linea: linea)),
        ),
      ),
    );
  }
}

class _Aviso extends StatelessWidget {
  const _Aviso({
    required this.icono,
    required this.mensaje,
    this.esError = false,
    this.alReintentar,
  });

  final IconData icono;
  final String mensaje;
  final bool esError;
  final VoidCallback? alReintentar;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: FpEstadoVacio(
        icono: icono,
        titulo: mensaje,
        error: esError,
        accion: alReintentar == null
            ? null
            : FilledButton.icon(
                onPressed: alReintentar,
                icon: const Icon(Icons.refresh),
                label: const Text('Reintentar'),
              ),
      ),
    );
  }
}
