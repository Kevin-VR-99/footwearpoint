import 'package:flutter/material.dart';
import '../services/api_service.dart';

class NotificacionesScreen extends StatefulWidget {
  const NotificacionesScreen({super.key});

  @override
  State<NotificacionesScreen> createState() => _NotificacionesScreenState();
}

class _NotificacionesScreenState extends State<NotificacionesScreen> {
  final ApiService _api = ApiService();
  bool _cargando = true;
  List<dynamic> _notificaciones = [];
  String? _error;

  @override
  void initState() {
    super.initState();
    _cargarNotificaciones();
  }

  Future<void> _cargarNotificaciones() async {
    setState(() {
      _cargando = true;
      _error = null;
    });

    try {
      final respuesta = await _api.get('notificaciones');
      setState(() {
        _notificaciones = respuesta['data'] as List<dynamic>? ?? [];
        _cargando = false;
      });
    } catch (e) {
      setState(() {
        _error = e.toString();
        _cargando = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Bandeja de Notificaciones')),
      body: RefreshIndicator(
        onRefresh: _cargarNotificaciones,
        child: _cargando
            ? const Center(child: CircularProgressIndicator())
            : _error != null
                ? Center(child: Text('Error al cargar: $_error', style: const TextStyle(color: Colors.red)))
                : _notificaciones.isEmpty
                    ? const Center(child: Text('No tienes notificaciones recientes.'))
                    : ListView.builder(
                        itemCount: _notificaciones.length,
                        padding: const EdgeInsets.all(16),
                        itemBuilder: (context, index) {
                          final notif = _notificaciones[index] as Map<String, dynamic>;
                          final titulo = notif['titulo'] ?? 'Actualización de Pedido';
                          final mensaje = notif['mensaje'] ?? notif['cuerpo'] ?? '';
                          final fecha = notif['created_at'] ?? '';

                          return Card(
                            margin: const EdgeInsets.only(bottom: 12),
                            child: ListTile(
                              leading: const Icon(Icons.notifications_active_outlined, color: Color(0xFF6D4C41)),
                              title: Text(titulo, style: const TextStyle(fontWeight: FontWeight.bold)),
                              subtitle: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  const SizedBox(height: 4),
                                  Text(mensaje),
                                  if (fecha.isNotEmpty) ...[
                                    const SizedBox(height: 6),
                                    Text(
                                      fecha.toString().split('T')[0],
                                      style: const TextStyle(color: Colors.grey, fontSize: 12),
                                    ),
                                  ],
                                ],
                              ),
                            ),
                          );
                        },
                      ),
      ),
    );
  }
}