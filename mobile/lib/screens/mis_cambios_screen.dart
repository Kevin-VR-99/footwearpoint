import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../models/solicitud_cambio.dart';
import '../providers/auth_provider.dart';
import '../services/api_service.dart';
import '../services/cambio_service.dart';
import '../tema/fp_colores.dart';
import '../widgets/fp_componentes.dart';
import 'historial_cambio_screen.dart';

/// Las solicitudes de cambio de quien inició sesión. Al tocar una se abre
/// su historial (línea de tiempo).
class MisCambiosScreen extends StatefulWidget {
  const MisCambiosScreen({super.key});

  @override
  State<MisCambiosScreen> createState() => _MisCambiosScreenState();
}

class _MisCambiosScreenState extends State<MisCambiosScreen> {
  late final CambioService _servicio = CambioService(context.read<AuthProvider>().api);

  List<SolicitudCambio>? _solicitudes;
  String? _error;
  bool _cargando = true;

  @override
  void initState() {
    super.initState();
    _cargar();
  }

  Future<void> _cargar() async {
    try {
      final lista = await _servicio.listar();
      if (!mounted) return;
      setState(() {
        _solicitudes = lista;
        _error = null;
        _cargando = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.mensaje;
        _cargando = false;
      });
    }
  }

  Future<void> _abrir(SolicitudCambio solicitud) async {
    await Navigator.of(context).push(
      MaterialPageRoute<void>(builder: (_) => HistorialCambioScreen(solicitudId: solicitud.id)),
    );
    if (mounted) _cargar();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Mis cambios'), bottom: const FpBordeMarca()),
      body: SafeArea(child: _contenido()),
    );
  }

  Widget _contenido() {
    if (_cargando) return const Center(child: CircularProgressIndicator());

    final lista = _solicitudes;
    if (lista == null) {
      return Center(
        child: FpEstadoVacio(
          error: true,
          icono: Icons.cloud_off_outlined,
          titulo: _error ?? 'No se pudieron cargar tus cambios.',
          accion: FilledButton.icon(
            onPressed: () {
              setState(() => _cargando = true);
              _cargar();
            },
            icon: const Icon(Icons.refresh),
            label: const Text('Reintentar'),
          ),
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: _cargar,
      child: lista.isEmpty
          ? ListView(
              children: const [
                SizedBox(height: 80),
                FpEstadoVacio(
                  icono: Icons.swap_horiz,
                  titulo: 'No tienes solicitudes de cambio',
                  texto: 'Puedes pedir un cambio desde el detalle de un pedido entregado.',
                ),
              ],
            )
          : ListView.separated(
              padding: const EdgeInsets.all(16),
              itemCount: lista.length,
              separatorBuilder: (_, _) => const SizedBox(height: 8),
              itemBuilder: (context, i) {
                final s = lista[i];
                return Card(
                  margin: EdgeInsets.zero,
                  child: ListTile(
                    leading: const FpIconoCuadro(icono: Icons.swap_horiz, tamano: 36),
                    title: Text(s.productoNombre ?? 'Solicitud #${s.id}'),
                    subtitle: Text(
                      [s.estadoEtiqueta, if (s.fechaSolicitud != null) 'Pedida el ${formatearCdmx(s.fechaSolicitud)}'].join(' · '),
                    ),
                    trailing: const Icon(Icons.chevron_right, color: FpColores.textoTenue),
                    onTap: () => _abrir(s),
                  ),
                );
              },
            ),
    );
  }
}
