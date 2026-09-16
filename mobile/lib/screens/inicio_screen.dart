import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../providers/auth_provider.dart';
import 'catalogo_screen.dart';
import 'perfil_screen.dart';
import 'clientes_privados_screen.dart';
import 'vales_screen.dart';

/// Pantalla provisional de después del login.
///
/// Enseña tal cual lo que respondió el servidor. No es una pantalla de
/// producto: existe para comprobar a simple vista que el login funcionó y
/// que el backend reconoce bien el rol y la distribuidora del usuario.
/// Las pantallas de verdad las construyen Ailton y Aurelio encima de esto.
class InicioScreen extends StatelessWidget {
  const InicioScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();
    final usuario = auth.usuario;

    return Scaffold(
      appBar: AppBar(title: const Text('FootwearPoint')),
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const Text(
                'Sesión iniciada',
                style: TextStyle(fontSize: 22, fontWeight: FontWeight.bold),
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
                          MaterialPageRoute<void>(builder: (_) => const CatalogoScreen()),
                        ),
                icon: const Icon(Icons.storefront_outlined),
                label: const Text('Ver catálogo'),
              ),
              const SizedBox(height: 12),
              FilledButton.tonalIcon(
                onPressed: auth.ocupado
                    ? null
                    : () => Navigator.of(context).push(
                          MaterialPageRoute<void>(builder: (_) => const ClientesPrivadosScreen()),
                        ),
                icon: const Icon(Icons.group_outlined),
                label: const Text('Mis Clientes Particulares'),
              ),
              const SizedBox(height: 12),
              FilledButton.tonalIcon(
                onPressed: auth.ocupado
                    ? null
                    : () => Navigator.of(context).push(
                          MaterialPageRoute<void>(builder: (_) => const ValesScreen()),
                        ),
                icon: const Icon(Icons.confirmation_number_outlined),
                label: const Text('Consultar y Aplicar Vales'),
              ),
              const SizedBox(height: 12),
              FilledButton.tonalIcon(
                onPressed: auth.ocupado
                    ? null
                    : () => Navigator.of(context).push(
                          MaterialPageRoute<void>(builder: (_) => const PerfilScreen()),
                        ),
                icon: const Icon(Icons.person_outline),
                label: const Text('Mi perfil'),
              ),
              const SizedBox(height: 12),
              OutlinedButton(
                onPressed: auth.ocupado ? null : () => context.read<AuthProvider>().logout(),
                child: const Text('Cerrar sesión'),
              ),
            ],
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
    // Un valor vacío se marca en rojo: si el rol o la distribuidora salen
    // vacíos, es justo la señal de que algo falta del lado del backend.
    final vacio = valor == null || valor!.isEmpty;

    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 120,
            child: Text(etiqueta, style: const TextStyle(fontWeight: FontWeight.w600)),
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