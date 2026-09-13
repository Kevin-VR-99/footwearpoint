import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../providers/auth_provider.dart';

/// Pantalla de inicio de sesión (E1-01).
///
/// Solo valida lo mínimo antes de llamar al servidor (que no falte nada y
/// que el correo tenga forma de correo). Quién puede entrar y con qué rol lo
/// decide Laravel en POST /api/auth/login, no esta pantalla.
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formulario = GlobalKey<FormState>();
  final _email = TextEditingController();
  final _password = TextEditingController();

  bool _passwordVisible = false;

  /// Revisa solo la forma: algo@algo.algo. La regla 'email' de Laravel
  /// (LoginRequest) es la que manda; esto evita un viaje al servidor por un
  /// error de dedo.
  static final _formatoCorreo = RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$');

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  String? _validarCorreo(String? valor) {
    final correo = valor?.trim() ?? '';

    if (correo.isEmpty) return 'Escribe tu correo.';
    // Mismo texto que manda LoginRequest del backend.
    if (!_formatoCorreo.hasMatch(correo)) return 'El correo no es válido.';

    return null;
  }

  // No se exige mínimo de caracteres aquí: el login del backend no lo pide,
  // y ponerlo podría dejar fuera a cuentas que ya existen. El mínimo de 8 se
  // aplica al crear o cambiar la contraseña, no al entrar.
  String? _validarPassword(String? valor) {
    return (valor == null || valor.isEmpty) ? 'Escribe tu contraseña.' : null;
  }

  Future<void> _entrar() async {
    if (!_formulario.currentState!.validate()) return;

    FocusScope.of(context).unfocus();

    final entro = await context.read<AuthProvider>().login(
      _email.text.trim(),
      _password.text,
    );

    // Le avisa al teléfono que el login funcionó, para que ofrezca guardar
    // la contraseña en su gestor (si el usuario tiene uno).
    if (entro) TextInput.finishAutofillContext();
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();
    final tema = Theme.of(context);

    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              // En tabletas o celulares acostados no se estira de lado a lado.
              constraints: const BoxConstraints(maxWidth: 420),
              child: AutofillGroup(
                child: Form(
                  key: _formulario,
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      _Encabezado(tema: tema),
                      const SizedBox(height: 40),
                      TextFormField(
                        controller: _email,
                        enabled: !auth.ocupado,
                        decoration: const InputDecoration(
                          labelText: 'Correo',
                          prefixIcon: Icon(Icons.mail_outline),
                          border: OutlineInputBorder(),
                        ),
                        keyboardType: TextInputType.emailAddress,
                        autofillHints: const [AutofillHints.email],
                        autocorrect: false,
                        textInputAction: TextInputAction.next,
                        validator: _validarCorreo,
                      ),
                      const SizedBox(height: 16),
                      TextFormField(
                        controller: _password,
                        enabled: !auth.ocupado,
                        decoration: InputDecoration(
                          labelText: 'Contraseña',
                          prefixIcon: const Icon(Icons.lock_outline),
                          border: const OutlineInputBorder(),
                          suffixIcon: IconButton(
                            tooltip: _passwordVisible
                                ? 'Ocultar contraseña'
                                : 'Mostrar contraseña',
                            icon: Icon(
                              _passwordVisible
                                  ? Icons.visibility_off_outlined
                                  : Icons.visibility_outlined,
                            ),
                            onPressed: () =>
                                setState(() => _passwordVisible = !_passwordVisible),
                          ),
                        ),
                        obscureText: !_passwordVisible,
                        autofillHints: const [AutofillHints.password],
                        textInputAction: TextInputAction.done,
                        onFieldSubmitted: (_) => _entrar(),
                        validator: _validarPassword,
                      ),
                      if (auth.error != null) ...[
                        const SizedBox(height: 16),
                        _AvisoError(mensaje: auth.error!),
                      ],
                      const SizedBox(height: 24),
                      FilledButton(
                        onPressed: auth.ocupado ? null : _entrar,
                        style: FilledButton.styleFrom(
                          minimumSize: const Size.fromHeight(52),
                        ),
                        child: auth.ocupado
                            ? const SizedBox(
                                height: 20,
                                width: 20,
                                child: CircularProgressIndicator(strokeWidth: 2),
                              )
                            : const Text('Entrar'),
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

class _Encabezado extends StatelessWidget {
  const _Encabezado({required this.tema});

  final ThemeData tema;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        CircleAvatar(
          radius: 36,
          backgroundColor: tema.colorScheme.primaryContainer,
          child: Icon(
            Icons.storefront_outlined,
            size: 36,
            color: tema.colorScheme.onPrimaryContainer,
          ),
        ),
        const SizedBox(height: 16),
        Text(
          'FootwearPoint',
          textAlign: TextAlign.center,
          style: tema.textTheme.headlineMedium?.copyWith(fontWeight: FontWeight.bold),
        ),
        const SizedBox(height: 8),
        Text(
          'Inicia sesión para continuar',
          textAlign: TextAlign.center,
          style: tema.textTheme.bodyLarge?.copyWith(
            color: tema.colorScheme.onSurfaceVariant,
          ),
        ),
      ],
    );
  }
}

/// El motivo por el que no se pudo entrar (credenciales, cuenta inactiva,
/// sin conexión) o el aviso de que la sesión expiró.
class _AvisoError extends StatelessWidget {
  const _AvisoError({required this.mensaje});

  final String mensaje;

  @override
  Widget build(BuildContext context) {
    final colores = Theme.of(context).colorScheme;

    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: colores.errorContainer,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(Icons.error_outline, color: colores.onErrorContainer),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              mensaje,
              style: TextStyle(color: colores.onErrorContainer),
            ),
          ),
        ],
      ),
    );
  }
}
