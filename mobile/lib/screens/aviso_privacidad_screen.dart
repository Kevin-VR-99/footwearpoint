import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../providers/auth_provider.dart';
import '../tema/fp_colores.dart';

class AvisoPrivacidadScreen extends StatefulWidget {
  const AvisoPrivacidadScreen({super.key});

  @override
  State<AvisoPrivacidadScreen> createState() => _AvisoPrivacidadScreenState();
}

class _AvisoPrivacidadScreenState extends State<AvisoPrivacidadScreen> {
  bool _isLoading = false;
  bool _aceptoTodo = false;

  Future<void> _aceptarYLlenarRegistro() async {
    if (!_aceptoTodo) return;

    setState(() {
      _isLoading = true;
    });

    try {
      final api = context.read<AuthProvider>().api;

      // Petición al endpoint correspondiente para guardar la aceptación en Laravel
      await api.post(
        'auth/aceptar-legales',
        cuerpo: {
          'documentos': [
            {'tipo_documento': 'aviso_privacidad', 'version': '1.0'},
            {'tipo_documento': 'terminos_condiciones', 'version': '1.0'},
          ]
        },
      );

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Aceptación registrada exitosamente'),
            backgroundColor: Colors.green,
          ),
        );
        Navigator.pop(context, true);
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Error al registrar la aceptación: $e'),
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
          child: Padding(
            padding: const EdgeInsets.all(20.0),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                const Text(
                  'Aviso de Privacidad y Términos de Uso',
                  style: TextStyle(
                    fontSize: 20,
                    fontWeight: FontWeight.bold,
                    color: Colors.black87,
                  ),
                ),
                const SizedBox(height: 6),
                const Text(
                  'Para continuar operando en FootwearPoint, es obligatorio leer y aceptar nuestras políticas vigentes.',
                  style: TextStyle(fontSize: 14, color: Colors.black54),
                ),
                const SizedBox(height: 16),
                Expanded(
                  child: Container(
                    padding: const EdgeInsets.all(16),
                    decoration: BoxDecoration(
                      color: Colors.white,
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: Colors.grey.shade300),
                    ),
                    child: Scrollbar(
                      thumbVisibility: true,
                      child: SingleChildScrollView(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: const [
                            Text(
                              '1. Identidad y Domicilio del Responsable',
                              style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                            ),
                            SizedBox(height: 6),
                            Text(
                              'FootwearPoint, en cumplimiento con la Ley Federal de Protección de Datos Personales, es responsable del tratamiento y resguardo de la información proporcionada por los usuarios y distribuidores de la plataforma.',
                              style: TextStyle(fontSize: 13, color: Colors.black87),
                            ),
                            SizedBox(height: 14),
                            Text(
                              '2. Finalidad del Tratamiento de Datos',
                              style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                            ),
                            SizedBox(height: 6),
                            Text(
                              'Los datos personales recabados (nombre, correo electrónico, teléfono y datos de transacciones de calzado) serán utilizados exclusivamente para: gestión de pedidos, control de inventarios de distribuidores, notificaciones push y autenticación segura en el sistema.',
                              style: TextStyle(fontSize: 13, color: Colors.black87),
                            ),
                            SizedBox(height: 14),
                            Text(
                              '3. Consentimiento y Versión',
                              style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                            ),
                            SizedBox(height: 6),
                            Text(
                              'Al marcar la casilla de aceptación y continuar, usted otorga su consentimiento expreso para el registro de su aceptación en nuestros servidores seguros.',
                              style: TextStyle(fontSize: 13, color: Colors.black87),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
                ),
                const SizedBox(height: 16),
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
                      const Expanded(
                        child: Text(
                          'He leído y acepto el Aviso de Privacidad y los Términos de Uso.',
                          style: TextStyle(fontSize: 13, fontWeight: FontWeight.w500),
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
              ],
            ),
          ),
        ),
      ),
    );
  }
}