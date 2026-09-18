import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../providers/auth_provider.dart';
import '../models/vale.dart';
import '../services/vale_service.dart';
import '../tema/fp_colores.dart';
import '../widgets/fp_componentes.dart';

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
      appBar: AppBar(title: const Text('Mis Vales Vigentes'), bottom: const FpBordeMarca()),
      body: _cargando
          ? const Center(child: CircularProgressIndicator())
          : _error != null
          ? Center(
              child: FpEstadoVacio(error: true, icono: Icons.cloud_off_outlined, titulo: 'Error: $_error'),
            )
          : _vales.isEmpty
          ? const Center(
              child: FpEstadoVacio(
                icono: Icons.confirmation_number_outlined,
                titulo: 'No tienes vales activos o con saldo disponible.',
              ),
            )
          : RefreshIndicator(
              onRefresh: _cargarVales,
              child: ListView.builder(
                itemCount: _vales.length,
                padding: const EdgeInsets.all(16),
                itemBuilder: (context, index) {
                  final vale = _vales[index];
                  final bool estaVencido =
                      vale.fechaVencimiento != null && vale.fechaVencimiento!.isBefore(DateTime.now());

                  return Card(
                    margin: const EdgeInsets.only(bottom: 12),
                    child: ListTile(
                      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
                      leading: const FpIconoCuadro(
                        icono: Icons.confirmation_number_outlined,
                        fondo: FpColores.peligroSuave,
                        color: FpColores.peligro,
                      ),
                      title: Text(
                        'Folio: ${vale.folio}',
                        style: const TextStyle(color: FpColores.sidebar, fontWeight: FontWeight.w600),
                      ),
                      subtitle: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const SizedBox(height: 4),
                          Text(
                            'Saldo Actual: \$${vale.saldoActual.toStringAsFixed(2)}',
                            style: const TextStyle(color: FpColores.texto, fontWeight: FontWeight.w600),
                          ),
                          if (vale.fechaVencimiento != null)
                            Text(
                              'Vence: ${vale.fechaVencimiento!.day.toString().padLeft(2, '0')}/${vale.fechaVencimiento!.month.toString().padLeft(2, '0')}/${vale.fechaVencimiento!.year}${estaVencido ? ' (VENCIDO)' : ''}',
                              style: TextStyle(
                                color: estaVencido ? FpColores.peligro : FpColores.textoTenue,
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
