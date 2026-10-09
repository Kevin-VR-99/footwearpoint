import 'package:flutter/material.dart';
import '../services/api_service.dart';

class AvisoPrivacidadScreen extends StatefulWidget {
  const AvisoPrivacidadScreen({Key? key}) : super(key: key);

  @override
  _AvisoPrivacidadScreenState createState() => _AvisoPrivacidadScreenState();
}

class _AvisoPrivacidadScreenState extends State<AvisoPrivacidadScreen> {
  bool _isLoading = false;
  bool _aceptoPrivacidad = false;
  bool _aceptoTerminos = false;

  // Valida que ambos documentos requeridos estén marcados (Criterio de aceptación: aceptación explícita)
  bool get _puedeContinuar => _aceptoPrivacidad && _aceptoTerminos;

  Future<void> _aceptarYLlenarRegistro() async {
    if (!_puedeContinuar) return;

    setState(() {
      _isLoading = true;
    });

    try {
      // Envía el consentimiento al endpoint del backend para registrar versión y fecha
      final successPrivacidad = await ApiService.post('/auth/aceptar-legales', {
        'tipo_documento': 'aviso_privacidad',
        'version': '1.0',
      });

      final successTerminos = await ApiService.post('/auth/aceptar-legales', {
        'tipo_documento': 'terminos_condiciones',
        'version': '1.0',
      });

      if (successPrivacidad && successTerminos) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Aceptación registrada exitosamente')),
        );
        // Cierra la pantalla y permite completar el registro/flujo
        Navigator.pop(context, true);
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Error al registrar la aceptación en el servidor')),
        );
      }
    } catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Ocurrió un error de conexión: $e')),
      );
    } finally {
      setState(() {
        _isLoading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Aviso de Privacidad y Términos'),
        automaticallyImplyLeading: false, // Evita salir sin aceptar (Criterio de aceptación)
      ),
      body: Padding(
        padding: const EdgeInsets.all(16.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Términos y Condiciones de Uso',
              style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
            ),
            const SizedBox(height: 8),
            const Expanded(
              child: SingleChildScrollView(
                child: Text(
                  'Por favor, revise detenidamente los términos y el aviso de privacidad de FootwearPoint. '
                  'Para cumplir con las normativas vigentes y poder hacer uso de la plataforma, '
                  'es indispensable aceptar de manera explícita ambas políticas de uso, las cuales '
                  'almacenarán la fecha y la versión actual del documento aceptado.',
                  style: TextStyle(fontSize: 15, color: Colors.black87),
                ),
              ),
            ),
            const SizedBox(height: 16),
            CheckboxListTile(
              title: const Text('He leído y acepto el Aviso de Privacidad'),
              value: _aceptoPrivacidad,
              onChanged: (val) {
                setState(() {
                  _aceptoPrivacidad = val ?? false;
                });
              },
              controlAffinity: ListTileControlAffinity.leading,
              contentPadding: EdgeInsets.zero,
            ),
            CheckboxListTile(
              title: const Text('He leído y acepto los Términos y Condiciones'),
              value: _aceptoTerminos,
              onChanged: (val) {
                setState(() {
                  _aceptoTerminos = val ?? false;
                });
              },
              controlAffinity: ListTileControlAffinity.leading,
              contentPadding: EdgeInsets.zero,
            ),
            const SizedBox(height: 16),
            SizedBox(
              width: double.infinity,
              height: 48,
              child: ElevatedButton(
                onPressed: (_puedeContinuar && !_isLoading) ? _aceptarYLlenarRegistro : null,
                child: _isLoading
                    ? const CircularProgressIndicator(color: Colors.white)
                    : const Text(
                        'Aceptar y Continuar',
                        style: TextStyle(fontSize: 16),
                      ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}