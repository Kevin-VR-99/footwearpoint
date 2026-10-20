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
  late final ValeService _valeService = ValeService(
    api: context.read<AuthProvider>().api,
  );
  bool _cargando = true;
  List<Vale> _vales = [];

  /// TG-281: filtra por folio. Quien tiene varios vales busca uno concreto.
  String _busqueda = '';
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
      appBar: AppBar(
        title: const Text('Mis Vales Vigentes'),
        bottom: const FpBordeMarca(),
      ),
      body: _cargando
          ? const Center(child: CircularProgressIndicator())
          : _error != null
          ? Center(
              child: FpEstadoVacio(
                error: true,
                icono: Icons.cloud_off_outlined,
                titulo: 'Error: $_error',
              ),
            )
          : _vales.isEmpty
          ? const Center(
              child: FpEstadoVacio(
                icono: Icons.confirmation_number_outlined,
                titulo: 'No tienes vales activos o con saldo disponible.',
              ),
            )
          : _conBuscador(_lista()),
    );
  }

  /// El buscador fijo arriba y la lista debajo.
  Widget _conBuscador(Widget lista) {
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
          child: FpBuscador(
            texto: 'Buscar por folio',
            alCambiar: (valor) => setState(() => _busqueda = valor),
          ),
        ),
        Expanded(child: lista),
      ],
    );
  }

  Widget _lista() {
    final encontrados = filtrarVales(_vales, _busqueda);

    if (encontrados.isEmpty) {
      return RefreshIndicator(
        onRefresh: _cargarVales,
        child: ListView(
          children: [FpSinResultados(busqueda: _busqueda, que: 'vales')],
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: _cargarVales,
      child: ListView.builder(
        itemCount: encontrados.length,
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
        itemBuilder: (context, index) {
          final vale = encontrados[index];
          final bool estaVencido =
              vale.fechaVencimiento != null &&
              vale.fechaVencimiento!.isBefore(DateTime.now());

          // TG-122: resaltado si vence pronto (el margen lo pone el servidor).
          final bool vencePronto = vale.porVencer && !estaVencido;

          return Card(
            margin: const EdgeInsets.only(bottom: 12),
            shape: vencePronto
                ? RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(16),
                    side: const BorderSide(
                      color: Color(0xFFFDBA74),
                      width: 1.5,
                    ), // orange-300
                  )
                : null,
            child: ListTile(
              contentPadding: const EdgeInsets.symmetric(
                horizontal: 16,
                vertical: 6,
              ),
              leading: const FpIconoCuadro(
                icono: Icons.confirmation_number_outlined,
                fondo: FpColores.peligroSuave,
                color: FpColores.peligro,
              ),
              title: Text(
                'Folio: ${vale.folio}',
                style: const TextStyle(
                  color: FpColores.sidebar,
                  fontWeight: FontWeight.w600,
                ),
              ),
              subtitle: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const SizedBox(height: 4),
                  Text(
                    'Saldo Actual: \$${vale.saldoActual.toStringAsFixed(2)}',
                    style: const TextStyle(
                      color: FpColores.texto,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  if (vale.fechaVencimiento != null)
                    Text(
                      'Vence: ${vale.fechaVencimiento!.day.toString().padLeft(2, '0')}/${vale.fechaVencimiento!.month.toString().padLeft(2, '0')}/${vale.fechaVencimiento!.year}${estaVencido ? ' (VENCIDO)' : ''}',
                      style: TextStyle(
                        color: estaVencido
                            ? FpColores.peligro
                            : FpColores.textoTenue,
                        fontWeight: estaVencido
                            ? FontWeight.bold
                            : FontWeight.normal,
                      ),
                    ),
                  if (vencePronto) ...[
                    const SizedBox(height: 6),
                    Container(
                      padding: const EdgeInsets.symmetric(
                        horizontal: 8,
                        vertical: 3,
                      ),
                      decoration: BoxDecoration(
                        color: FpColores.insigniaAvisoFondo,
                        borderRadius: BorderRadius.circular(999),
                      ),
                      child: const Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(
                            Icons.schedule,
                            size: 13,
                            color: FpColores.insigniaAvisoTexto,
                          ),
                          SizedBox(width: 4),
                          Text(
                            'Vence pronto',
                            style: TextStyle(
                              color: FpColores.insigniaAvisoTexto,
                              fontSize: 12,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}

/// Filtra los vales por folio, sin distinguir mayúsculas.
List<Vale> filtrarVales(List<Vale> vales, String busqueda) {
  final texto = busqueda.toLowerCase().trim();

  if (texto.isEmpty) return vales;

  return vales
      .where((vale) => vale.folio.toLowerCase().contains(texto))
      .toList();
}
