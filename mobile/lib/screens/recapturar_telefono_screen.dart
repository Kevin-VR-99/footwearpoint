import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../providers/auth_provider.dart';
import '../services/api_service.dart';

/// Pide un teléfono de 10 dígitos cuando el servidor vació el anterior por estar repetido.
class RecapturarTelefonoScreen extends StatefulWidget {
  const RecapturarTelefonoScreen({super.key});

  @override
  State<RecapturarTelefonoScreen> createState() => _RecapturarTelefonoScreenState();
}

class _RecapturarTelefonoScreenState extends State<RecapturarTelefonoScreen> {
  final _telefono = TextEditingController();
  bool _guardando = false;
  String? _error;

  @override
  void dispose() {
    _telefono.dispose();
    super.dispose();
  }

  Future<void> _guardar() async {
    setState(() {
      _guardando = true;
      _error = null;
    });

    try {
      await context.read<AuthProvider>().api.post('perfil/recapturar-telefono', cuerpo: {
        'telefono': _telefono.text.trim(),
      });
      if (!mounted) return;
      context.read<AuthProvider>().confirmarTelefonoRecapturado();
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.mensaje);
    } catch (_) {
      if (mounted) setState(() => _error = 'No se pudo guardar el teléfono.');
    } finally {
      if (mounted) setState(() => _guardando = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Tu teléfono')),
      body: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Text('El teléfono que tenías estaba repetido. Escríbelo otra vez, con 10 dígitos.'),
            const SizedBox(height: 16),
            TextField(
              controller: _telefono,
              keyboardType: TextInputType.phone,
              decoration: const InputDecoration(labelText: 'Teléfono'),
            ),
            if (_error != null) ...[
              const SizedBox(height: 12),
              Text(_error!, style: const TextStyle(color: Colors.red)),
            ],
            const SizedBox(height: 16),
            FilledButton(
              onPressed: _guardando ? null : _guardar,
              child: Text(_guardando ? 'Guardando...' : 'Guardar'),
            ),
          ],
        ),
      ),
    );
  }
}
