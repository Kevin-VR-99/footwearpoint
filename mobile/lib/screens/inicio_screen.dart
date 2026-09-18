import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../providers/auth_provider.dart';
import 'catalogo_screen.dart';
import 'mis_pedidos_screen.dart';
import 'perfil_screen.dart';
import 'clientes_privados_screen.dart';
import 'vales_screen.dart';
import 'notificaciones_screen.dart';

class InicioScreen extends StatelessWidget {
  const InicioScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();
    final usuario = auth.usuario;

    return Scaffold(
      appBar: AppBar(
        title: const Text('FootwearPoint'),
        actions: [
          // Ícono de campana de notificaciones clásico en la esquina superior derecha
          IconButton(
            icon: const Icon(Icons.notifications_outlined),
            tooltip: 'Bandeja de Notificaciones',
            onPressed: auth.ocupado
                ? null
                : () => Navigator.of(context).push(
                    MaterialPageRoute<void>(
                      builder: (_) => const NotificacionesScreen(),
                    ),
                  ),
          ),
          const SizedBox(width: 8),
        ],
      ),
      // Se puede deslizar si no cabe (celulares chicos): con tantos botones, la
      // columna fija se desbordaba y tapaba "Cerrar sesión". En pantallas
      // grandes se ve igual que antes: la altura mínima deja los botones abajo.
      body: SafeArea(
        child: LayoutBuilder(
          builder: (context, limites) => SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: BoxConstraints(minHeight: limites.maxHeight - 48),
              child: IntrinsicHeight(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    const Text(
                      'Sesión iniciada',
                      style: TextStyle(
                        fontSize: 22,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                    const SizedBox(height: 24),
                    _Dato(etiqueta: 'Nombre', valor: usuario?.nombre),
                    _Dato(etiqueta: 'Correo', valor: usuario?.email),
                    _Dato(etiqueta: 'Rol', valor: auth.rol),
                    _Dato(
                      etiqueta: 'Distribuidora',
                      valor: auth.distribuidoraId?.toString(),
                    ),
                    const Spacer(),
                    FilledButton.icon(
                      onPressed: auth.ocupado
                          ? null
                          : () => Navigator.of(context).push(
                              MaterialPageRoute<void>(
                                builder: (_) => const CatalogoScreen(),
                              ),
                            ),
                      icon: const Icon(Icons.storefront_outlined),
                      label: const Text('Ver catálogo'),
                    ),
                    const SizedBox(height: 12),
                    // TG-165: ver el estado de los pedidos propios desde la app.
                    FilledButton.tonalIcon(
                      onPressed: auth.ocupado
                          ? null
                          : () => Navigator.of(context).push(
                              MaterialPageRoute<void>(
                                builder: (_) => const MisPedidosScreen(),
                              ),
                            ),
                      icon: const Icon(Icons.receipt_long_outlined),
                      label: const Text('Mis pedidos'),
                    ),
                    const SizedBox(height: 12),
                    FilledButton.tonalIcon(
                      onPressed: auth.ocupado
                          ? null
                          : () => Navigator.of(context).push(
                              MaterialPageRoute<void>(
                                builder: (_) => const ClientesPrivadosScreen(),
                              ),
                            ),
                      icon: const Icon(Icons.group_outlined),
                      label: const Text('Mis Clientes Particulares'),
                    ),
                    const SizedBox(height: 12),
                    FilledButton.tonalIcon(
                      onPressed: auth.ocupado
                          ? null
                          : () => Navigator.of(context).push(
                              MaterialPageRoute<void>(
                                builder: (_) => const ValesScreen(),
                              ),
                            ),
                      icon: const Icon(Icons.confirmation_number_outlined),
                      label: const Text('Consultar y Aplicar Vales'),
                    ),
                    const SizedBox(height: 12),
                    FilledButton.tonalIcon(
                      onPressed: auth.ocupado
                          ? null
                          : () => Navigator.of(context).push(
                              MaterialPageRoute<void>(
                                builder: (_) => const PerfilScreen(),
                              ),
                            ),
                      icon: const Icon(Icons.person_outline),
                      label: const Text('Mi perfil'),
                    ),
                    const SizedBox(height: 12),
                    OutlinedButton(
                      onPressed: auth.ocupado
                          ? null
                          : () => context.read<AuthProvider>().logout(),
                      child: const Text('Cerrar sesión'),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _Dato extends StatelessWidget {
  const _Dato({required this.etiqueta, required this.valor});

  final String etiqueta;
  final String? valor;

  @override
  Widget build(BuildContext context) {
    final vacio = valor == null || valor!.isEmpty;

    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 120,
            child: Text(
              etiqueta,
              style: const TextStyle(fontWeight: FontWeight.w600),
            ),
          ),
          Expanded(
            child: Text(
              vacio ? '(vacío)' : valor!,
              style: vacio
                  ? TextStyle(color: Theme.of(context).colorScheme.error)
                  : null,
            ),
          ),
        ],
      ),
    );
  }
}
