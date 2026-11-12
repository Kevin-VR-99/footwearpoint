import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../models/documento_legal.dart';
import '../providers/auth_provider.dart';
import '../services/api_service.dart';
import '../services/pago_mercado_pago_service.dart';
import '../tema/fp_colores.dart';

/// E1-04 / TG-323 (K24): aceptar el aviso de privacidad y los términos.
///
/// Es la pantalla raíz mientras falte aceptar la versión vigente de alguno.
/// Antes mostraba un resumen propio, escrito en la app, que no era el texto
/// real y mandaba la versión «1.0» fija. Ahora muestra los documentos que el
/// servidor dice que faltan, con su versión, y abre el texto completo en el
/// navegador. La versión que se guarda la pone el servidor.
class AvisoPrivacidadScreen extends StatefulWidget {
  const AvisoPrivacidadScreen({super.key, this.abrirEnlace = abrirEnNavegador});

  /// Para las pruebas: cómo se abre la página de cada documento.
  final AbrirEnlace abrirEnlace;

  @override
  State<AvisoPrivacidadScreen> createState() => _AvisoPrivacidadScreenState();
}

class _AvisoPrivacidadScreenState extends State<AvisoPrivacidadScreen> {
  bool _isLoading = false;
  bool _aceptoTodo = false;

  List<DocumentoLegal> _documentos(AuthProvider auth) =>
      auth.legalesPendientes.isEmpty ? DocumentoLegal.ambos : auth.legalesPendientes;

  Future<void> _leer(DocumentoLegal documento) async {
    final url = documento.url;
    if (url == null) return;

    final abierto = await widget.abrirEnlace(Uri.parse(url));
    if (!abierto && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('No se pudo abrir el documento. Búscalo en $url')),
      );
    }
  }

  Future<void> _aceptarYLlenarRegistro() async {
    if (!_aceptoTodo) return;

    setState(() {
      _isLoading = true;
    });

    try {
      final auth = context.read<AuthProvider>();

      await auth.api.post(
        'auth/aceptar-legales',
        cuerpo: {
          'documentos': [
            for (final documento in _documentos(auth)) {'tipo_documento': documento.tipo},
          ],
        },
      );

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Aceptación registrada exitosamente'),
            backgroundColor: Colors.green,
          ),
        );
        // E1-04: es la pantalla raíz mientras no acepte; al confirmarlo,
        // la app pasa sola al inicio.
        auth.confirmarLegalesAceptados();
      }
    } on ApiException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(e.mensaje),
            backgroundColor: Colors.red,
          ),
        );
      }
    } finally {
      if (mounted) {
        setState(() {
          _isLoading = false;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();
    final documentos = _documentos(auth);
    final tema = Theme.of(context);

    return PopScope(
      canPop: false,
      child: Scaffold(
        backgroundColor: Colors.grey[50],
        appBar: AppBar(
          title: const Text('Políticas y Privacidad'),
          automaticallyImplyLeading: false,
          backgroundColor: FpColores.sidebar,
          foregroundColor: Colors.white,
          elevation: 0,
        ),
        body: SafeArea(
          child: ListView(
            padding: const EdgeInsets.all(20),
            children: [
              const Text(
                'Documentos legales',
                style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold, color: Colors.black87),
              ),
              const SizedBox(height: 6),
              Text(
                'Para usar FootwearPoint, lee y acepta estos documentos. '
                'Si ya los habías aceptado, te los pedimos otra vez porque cambiaron.',
                style: tema.textTheme.bodyMedium?.copyWith(color: Colors.black87),
              ),
              const SizedBox(height: 16),
              for (final documento in documentos) ...[
                _TarjetaDocumento(documento: documento, alLeer: () => _leer(documento)),
                const SizedBox(height: 12),
              ],
              const SizedBox(height: 4),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(10),
                  border: Border.all(color: Colors.grey.shade300),
                ),
                child: Row(
                  children: [
                    Checkbox(
                      value: _aceptoTodo,
                      onChanged: (val) {
                        setState(() {
                          _aceptoTodo = val ?? false;
                        });
                      },
                    ),
                    Expanded(
                      child: Text(
                        documentos.length == 1 && documentos.first.tipo == 'aviso_privacidad'
                            ? 'He leído y acepto el aviso de privacidad.'
                            : documentos.length == 1
                            ? 'He leído y acepto los términos y condiciones.'
                            : 'He leído y acepto el aviso de privacidad y los términos y condiciones.',
                        style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w500),
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 16),
              SizedBox(
                height: 50,
                child: FilledButton(
                  onPressed: (_aceptoTodo && !_isLoading) ? _aceptarYLlenarRegistro : null,
                  style: FilledButton.styleFrom(
                    backgroundColor: FpColores.sidebar,
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(10),
                    ),
                  ),
                  child: _isLoading
                      ? const SizedBox(
                          width: 24,
                          height: 24,
                          child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2),
                        )
                      : const Text(
                          'Aceptar y Continuar',
                          style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                        ),
                ),
              ),
              const SizedBox(height: 8),
              // Si no acepta, la única salida es cerrar la sesión.
              TextButton(
                onPressed: _isLoading ? null : () => context.read<AuthProvider>().logout(),
                child: const Text('No acepto, cerrar sesión'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Un documento: su nombre, su versión y el botón para leerlo completo.
class _TarjetaDocumento extends StatelessWidget {
  const _TarjetaDocumento({required this.documento, required this.alLeer});

  final DocumentoLegal documento;
  final VoidCallback alLeer;

  @override
  Widget build(BuildContext context) {
    final detalle = [
      if (documento.version != null) 'Versión ${documento.version}',
      if (documento.fecha != null) 'actualizado el ${documento.fecha}',
    ].join(', ');

    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: Colors.grey.shade300),
      ),
      child: Row(
        children: [
          const Icon(Icons.description_outlined, color: FpColores.primario),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(documento.nombre, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
                if (detalle.isNotEmpty) ...[
                  const SizedBox(height: 2),
                  Text(detalle, style: const TextStyle(fontSize: 13, color: Colors.black87)),
                ],
              ],
            ),
          ),
          if (documento.url != null)
            TextButton(
              onPressed: alLeer,
              child: Text('Leer', semanticsLabel: 'Leer ${documento.nombre}'),
            ),
        ],
      ),
    );
  }
}
