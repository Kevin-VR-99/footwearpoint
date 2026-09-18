import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../providers/auth_provider.dart';
import '../models/vale.dart';
import '../services/vale_service.dart';

class ValesScreen extends StatefulWidget {
  const ValesScreen({super.key});

  @override
  State<ValesScreen> createState() => _ValesScreenState();
}

class _ValesScreenState extends State<ValesScreen> {
  // Con el ApiService de AuthProvider (no uno propio): así, si la sesión
  // expira aquí, el 401 regresa al login como en el resto de la app.
  late final ValeService _valeService = ValeService(api: context.read<AuthProvider>().api);
  bool _cargando = true;
  List<Vale> _vales = [];
  String? _error;

  @override
  void initState() {
    super.initState();
    _cargarVales();
  }

  Future<void> _cargarVales() async {
    setState(() {
      _cargando = true;
      _error = null;
    });

    try {
      final vales = await _valeService.listarValesVigentes();
      setState(() {
        _vales = vales;
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
      appBar: AppBar(title: const Text('Mis Vales Vigentes')),
      body: _cargando
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(child: Text('Error: $_error', style: const TextStyle(color: Colors.red)))
              : _vales.isEmpty
                  ? const Center(child: Text('No tienes vales activos o con saldo disponible.'))
                  : RefreshIndicator(
                      onRefresh: _cargarVales,
                      child: ListView.builder(
                        itemCount: _vales.length,
                        padding: const EdgeInsets.all(16),
                        itemBuilder: (context, index) {
                          final vale = _vales[index];
                          final bool estaVencido = vale.fechaVencimiento != null &&
                              vale.fechaVencimiento!.isBefore(DateTime.now());

                          return Card(
                            margin: const EdgeInsets.only(bottom: 12),
                            child: ListTile(
                              title: Text('Folio: ${vale.folio}', style: const TextStyle(fontWeight: FontWeight.bold)),
                              subtitle: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  const SizedBox(height: 4),
                                  Text('Saldo Actual: \$${vale.saldoActual.toStringAsFixed(2)}'),
                                  if (vale.fechaVencimiento != null)
                                    Text(
                                      'Vence: ${vale.fechaVencimiento!.day.toString().padLeft(2, '0')}/${vale.fechaVencimiento!.month.toString().padLeft(2, '0')}/${vale.fechaVencimiento!.year}${estaVencido ? ' (VENCIDO)' : ''}',
                                      style: TextStyle(
                                        color: estaVencido ? Colors.red : Colors.grey,
                                        fontWeight: estaVencido ? FontWeight.bold : FontWeight.normal,
                                      ),
                                    ),
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