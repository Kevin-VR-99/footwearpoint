import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../providers/auth_provider.dart';
import '../services/api_service.dart';
import 'login_screen.dart';

/// Cambiar la contraseña desde el perfil (E1-05).
///
/// Llama a POST /api/perfil/password. Si sale bien, el servidor cierra la
/// sesión en los otros teléfonos; este sigue dentro con su mismo token, así
/// que la app no tiene que hacer nada más. Al terminar regresa al perfil con
/// el mensaje del servidor, que el perfil muestra abajo.
class CambiarPasswordScreen extends StatefulWidget {
  const CambiarPasswordScreen({super.key});

  @override
  State<CambiarPasswordScreen> createState() => _CambiarPasswordScreenState();
}

class _CambiarPasswordScreenState extends State<CambiarPasswordScreen> {
  final _formulario = GlobalKey<FormState>();
  final _actual = TextEditingController();
  final _nueva = TextEditingController();
  final _confirmacion = TextEditingController();

  bool _guardando = false;
  String? _error;
  Map<String, List<String>> _erroresServidor = const {};

  @override
  void dispose() {
    _actual.dispose();
    _nueva.dispose();
    _confirmacion.dispose();
    super.dispose();
  }

  void _limpiarErrorServidor(String campo) {
    if (!_erroresServidor.containsKey(campo)) return;

    setState(() {
      _erroresServidor = Map.of(_erroresServidor)..remove(campo);
    });
  }

  Future<void> _guardar() async {
    if (!_formulario.currentState!.validate()) return;

    FocusScope.of(context).unfocus();

    final api = context.read<AuthProvider>().api;
    final navegador = Navigator.of(context);

    setState(() {
      _guardando = true;
      _error = null;
    });

    try {
      final respuesta = await api.post(
        'perfil/password',
        cuerpo: {
          'password_actual': _actual.text,
          'password': _nueva.text,
          'password_confirmation': _confirmacion.text,
        },
      );

      if (!mounted) return;
      navegador.pop(respuesta['message'] as String? ?? 'Contraseña actualizada.');
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _guardando = false;
        _erroresServidor = e.errores;
        _error = e.errores.isEmpty ? e.mensaje : null;
      });
    }
  }

  // Mismas reglas que CambiarPasswordRequest (y que el cambio por enlace).
  String? _validarNueva(String? valor) {
    if (valor == null || valor.isEmpty) return 'Escribe tu nueva contraseña.';
    if (valor.length < 8) return 'La contraseña debe tener al menos 8 caracteres.';
    return null;
  }

  String? _validarConfirmacion(String? valor) {
    if (valor != _nueva.text) return 'Las contraseñas no coinciden.';
    return null;
  }

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);

    return Scaffold(
      appBar: AppBar(title: const Text('Cambiar contraseña')),
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: AutofillGroup(
                child: Form(
                  key: _formulario,
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Text(
                        'Al cambiarla se cerrará tu sesión en tus otros dispositivos. '
                        'En este seguirás dentro.',
                        style: tema.textTheme.bodyMedium?.copyWith(
                          color: tema.colorScheme.onSurfaceVariant,
                        ),
                      ),
                      const SizedBox(height: 24),
                      _CampoPassword(
                        controller: _actual,
                        etiqueta: 'Contraseña actual',
                        habilitado: !_guardando,
                        autofill: AutofillHints.password,
                        validator: (valor) => (valor == null || valor.isEmpty)
                            ? 'Escribe tu contraseña actual.'
                            : null,
                        errorServidor: _erroresServidor['password_actual']?.first,
                        alCambiar: () => _limpiarErrorServidor('password_actual'),
                      ),
                      const SizedBox(height: 16),
                      _CampoPassword(
                        controller: _nueva,
                        etiqueta: 'Nueva contraseña',
                        ayuda: 'Mínimo 8 caracteres.',
                        habilitado: !_guardando,
                        autofill: AutofillHints.newPassword,
                        validator: _validarNueva,
                        errorServidor: _erroresServidor['password']?.first,
                        alCambiar: () => _limpiarErrorServidor('password'),
                      ),
                      const SizedBox(height: 16),
                      _CampoPassword(
                        controller: _confirmacion,
                        etiqueta: 'Confirma la nueva contraseña',
                        habilitado: !_guardando,
                        autofill: AutofillHints.newPassword,
                        validator: _validarConfirmacion,
                        alEnviar: _guardar,
                      ),
                      if (_error != null) ...[
                        const SizedBox(height: 16),
                        AvisoError(mensaje: _error!),
                      ],
                      const SizedBox(height: 24),
                      FilledButton(
                        onPressed: _guardando ? null : _guardar,
                        style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
                        child: _guardando
                            ? const SizedBox(
                                height: 20,
                                width: 20,
                                child: CircularProgressIndicator(strokeWidth: 2),
                              )
                            : const Text('Cambiar contraseña'),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// Un campo de contraseña con su propio botón del ojo.
class _CampoPassword extends StatefulWidget {
  const _CampoPassword({
    required this.controller,
    required this.etiqueta,
    required this.habilitado,
    required this.autofill,
    required this.validator,
    this.ayuda,
    this.errorServidor,
    this.alCambiar,
    this.alEnviar,
  });

  final TextEditingController controller;
  final String etiqueta;
  final String? ayuda;
  final bool habilitado;
  final String autofill;
  final FormFieldValidator<String> validator;
  final String? errorServidor;
  final VoidCallback? alCambiar;
  final VoidCallback? alEnviar;

  @override
  State<_CampoPassword> createState() => _CampoPasswordState();
}

class _CampoPasswordState extends State<_CampoPassword> {
  bool _visible = false;

  @override
  Widget build(BuildContext context) {
    return TextFormField(
      controller: widget.controller,
      enabled: widget.habilitado,
      obscureText: !_visible,
      autofillHints: [widget.autofill],
      textInputAction: widget.alEnviar == null ? TextInputAction.next : TextInputAction.done,
      onFieldSubmitted: widget.alEnviar == null ? null : (_) => widget.alEnviar!(),
      onChanged: widget.alCambiar == null ? null : (_) => widget.alCambiar!(),
      validator: widget.validator,
      forceErrorText: widget.errorServidor,
      decoration: InputDecoration(
        labelText: widget.etiqueta,
        helperText: widget.ayuda,
        prefixIcon: const Icon(Icons.lock_outline),
        border: const OutlineInputBorder(),
        suffixIcon: IconButton(
          tooltip: _visible ? 'Ocultar contraseña' : 'Mostrar contraseña',
          icon: Icon(_visible ? Icons.visibility_off_outlined : Icons.visibility_outlined),
          onPressed: () => setState(() => _visible = !_visible),
        ),
      ),
    );
  }
}
