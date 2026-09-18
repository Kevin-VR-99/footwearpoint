import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../models/notificacion.dart';
import '../models/pedido_resumen.dart';
import '../providers/auth_provider.dart';
import '../services/api_service.dart';
import '../services/notificacion_service.dart';
import 'pedido_detalle_screen.dart';

/// Bandeja de notificaciones dentro de la app (E16-01).
///
/// TG-165: antes solo mostraba la lista. Ahora las no leídas se distinguen,
/// al tocar una se marca como leída y, si es de un pedido, abre su detalle
/// (para eso el servidor manda entidad_tipo y entidad_id).
class NotificacionesScreen extends StatefulWidget {
  const NotificacionesScreen({super.key});

  @override
  State<NotificacionesScreen> createState() => _NotificacionesScreenState();
}

class _NotificacionesScreenState extends State<NotificacionesScreen> {
  // Con el ApiService de AuthProvider (no uno propio): así, si la sesión
  // expira aquí, el 401 regresa al login como en el resto de la app.
  late final _servicio = NotificacionService(context.read<AuthProvider>().api);

  List<Notificacion>? _notificaciones;
  bool _cargando = true;
  String? _error;

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
        _notificaciones = lista;
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

  Future<void> _tocar(Notificacion notificacion) async {
    if (!notificacion.leida) {
      // Se marca en pantalla de inmediato; si el servidor fallara, solo se
      // vería como leída hasta la próxima recarga (no es grave).
      setState(() {
        _notificaciones = [
          for (final n in _notificaciones!) n.id == notificacion.id ? n.marcadaLeida() : n,
        ];
      });

      try {
        await _servicio.marcarLeida(notificacion.id);
      } on ApiException {
        // Un 401 lo atiende AuthProvider; lo demás no vale la pena mostrarlo.
      }
    }

    final pedidoId = notificacion.pedidoId;
    if (pedidoId != null && mounted) {
      await Navigator.of(context).push(
        MaterialPageRoute<void>(builder: (_) => PedidoDetalleScreen(pedidoId: pedidoId)),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Notificaciones')),
      body: SafeArea(child: _contenido(context)),
    );
  }

  Widget _contenido(BuildContext context) {
    final tema = Theme.of(context);

    if (_cargando) return const Center(child: CircularProgressIndicator());

    final lista = _notificaciones;
    if (lista == null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(Icons.cloud_off_outlined, size: 64, color: tema.colorScheme.error),
              const SizedBox(height: 16),
              Text(_error ?? 'No se pudieron cargar tus notificaciones.', textAlign: TextAlign.center),
              const SizedBox(height: 16),
              FilledButton.icon(
                onPressed: () {
                  setState(() => _cargando = true);
                  _cargar();
                },
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
              children: [
                const SizedBox(height: 120),
                Icon(Icons.notifications_none, size: 64, color: tema.colorScheme.onSurfaceVariant),
                const SizedBox(height: 16),
                const Text('No tienes notificaciones recientes.', textAlign: TextAlign.center),
              ],
            )
          : ListView.separated(
              padding: const EdgeInsets.all(16),
              itemCount: lista.length,
              separatorBuilder: (_, _) => const SizedBox(height: 8),
              itemBuilder: (_, i) => _TarjetaNotificacion(
                notificacion: lista[i],
                alTocar: () => _tocar(lista[i]),
              ),
            ),
    );
  }
}

class _TarjetaNotificacion extends StatelessWidget {
  const _TarjetaNotificacion({required this.notificacion, required this.alTocar});

  final Notificacion notificacion;
  final VoidCallback alTocar;

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);
    final colores = tema.colorScheme;
    final nueva = !notificacion.leida;

    return Card(
      margin: EdgeInsets.zero,
      // Las no leídas con fondo de color, como en cualquier bandeja.
      color: nueva ? colores.primaryContainer.withValues(alpha: 0.5) : null,
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: alTocar,
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(
                nueva ? Icons.notifications_active : Icons.notifications_none,
                color: nueva ? colores.primary : colores.onSurfaceVariant,
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            notificacion.titulo,
                            style: TextStyle(fontWeight: nueva ? FontWeight.bold : FontWeight.w500),
                          ),
                        ),
                        if (nueva)
                          Tooltip(
                            message: 'Sin leer',
                            child: Container(
                              width: 10,
                              height: 10,
                              decoration: BoxDecoration(color: colores.primary, shape: BoxShape.circle),
                            ),
                          ),
                      ],
                    ),
                    const SizedBox(height: 4),
                    Text(notificacion.mensaje),
                    const SizedBox(height: 6),
                    Row(
                      children: [
                        if (notificacion.fecha != null)
                          Text(
                            formatoFecha(notificacion.fecha!),
                            style: tema.textTheme.bodySmall?.copyWith(color: colores.onSurfaceVariant),
                          ),
                        const Spacer(),
                        if (notificacion.pedidoId != null)
                          Text(
                            'Ver pedido',
                            style: tema.textTheme.bodySmall?.copyWith(
                              color: colores.primary,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
