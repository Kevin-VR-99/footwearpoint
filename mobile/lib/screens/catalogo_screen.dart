import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../models/producto_catalogo.dart';
import '../providers/auth_provider.dart';
import '../services/api_service.dart';
import '../services/catalogo_service.dart';
import 'login_screen.dart';
import 'productos_linea_screen.dart';
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
  Future<void> _cargar() async {
    final servicio = CatalogoService(context.read<AuthProvider>().api);

    try {
      final productos = await servicio.obtener();
      if (!mounted) return;

      setState(() {
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
      onRefresh: _cargar,
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
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                if (_error != null) ...[
                  AvisoError(mensaje: _error!),
                  const SizedBox(height: 12),
                ],
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
            ),
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
