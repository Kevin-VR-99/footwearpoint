import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../providers/auth_provider.dart';
import '../tema/fp_colores.dart';
import '../validaciones.dart';
import '../widgets/fp_componentes.dart';
import 'recuperar_password_screen.dart';
import 'aviso_privacidad_screen.dart';

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

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  String? _validarPassword(String? valor) {
    return (valor == null || valor.isEmpty) ? 'Escribe tu contraseña.' : null;
  }

  Future<void> _entrar() async {
    if (!_formulario.currentState!.validate()) return;

    FocusScope.of(context).unfocus();

    final authProvider = context.read<AuthProvider>();
    final entro = await authProvider.login(_email.text.trim(), _password.text);

    if (entro && mounted) {
      TextInput.finishAutofillContext();

      // Forzamos la verificación estricta del estado de legales del provider
      bool legalesAceptados = authProvider.legalesAceptados;

      // Si el backend dice que no los ha aceptado, se muestra el aviso obligatoriamente
      while (!legalesAceptados) {
        if (!mounted) return;
        
        final acepto = await Navigator.of(context).push<bool>(
          MaterialPageRoute(
            builder: (_) => const AvisoPrivacidadScreen(),
          ),
        );

        // Si cierra la pantalla sin aceptar (acepto != true), destruimos sesión y detenemos el flujo
        if (acepto != true) {
          await authProvider.logout();
          if (mounted) {
            ScaffoldMessenger.of(context).showSnackBar(
              const SnackBar(
                content: Text('Debe aceptar el aviso de privacidad para continuar.'),
                backgroundColor: Colors.red,
              ),
            );
          }
          return;
        }

        // Volvemos a consultar o actualizar el estado tras el intento
        legalesAceptados = authProvider.legalesAceptados;
      }

      // Si ya los aceptó correctamente, avanzamos al inicio
      if (mounted) {
        Navigator.pushReplacementNamed(context, '/inicio');
      }
    }
  }

  void _abrirRecuperarPassword() {
    Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => RecuperarPasswordScreen(correoInicial: _email.text.trim()),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();

    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: SystemUiOverlayStyle.light,
      child: Scaffold(
        backgroundColor: FpColores.sidebar,
        body: DecoratedBox(
          decoration: const BoxDecoration(gradient: FpColores.degradadoMenu),
          child: SafeArea(
            child: Column(
              children: [
                const FpLineaMarca(),
                Expanded(
                  child: Center(
                    child: SingleChildScrollView(
                      padding: const EdgeInsets.all(24),
                      child: ConstrainedBox(
                        constraints: const BoxConstraints(maxWidth: 420),
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            const _Encabezado(),
                            const SizedBox(height: 24),
                            FpTarjeta(
                              padding: const EdgeInsets.fromLTRB(20, 24, 20, 20),
                              child: _campos(auth),
                            ),
                          ],
                        ),
                      ),
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

  Widget _campos(AuthProvider auth) {
    return AutofillGroup(
      child: Form(
        key: _formulario,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            TextFormField(
              controller: _email,
              enabled: !auth.ocupado,
              decoration: const InputDecoration(
                labelText: 'Correo',
                prefixIcon: Icon(Icons.mail_outline),
              ),
              keyboardType: TextInputType.emailAddress,
              autofillHints: const [AutofillHints.email],
              autocorrect: false,
              textInputAction: TextInputAction.next,
              validator: Validaciones.correo,
            ),
            const SizedBox(height: 16),
            TextFormField(
              controller: _password,
              enabled: !auth.ocupado,
              decoration: InputDecoration(
                labelText: 'Contraseña',
                prefixIcon: const Icon(Icons.lock_outline),
                suffixIcon: IconButton(
                  tooltip: _passwordVisible ? 'Ocultar contraseña' : 'Mostrar contraseña',
                  icon: Icon(
                    _passwordVisible ? Icons.visibility_off_outlined : Icons.visibility_outlined,
                  ),
                  onPressed: () => setState(() => _passwordVisible = !_passwordVisible),
                ),
              ),
              obscureText: !_passwordVisible,
              autofillHints: const [AutofillHints.password],
              textInputAction: TextInputAction.done,
              onFieldSubmitted: (_) => _entrar(),
              validator: _validarPassword,
            ),
            Align(
              alignment: Alignment.centerRight,
              child: TextButton(
                onPressed: auth.ocupado ? null : _abrirRecuperarPassword,
                child: const Text('¿Olvidaste tu contraseña?'),
              ),
            ),
            if (auth.error != null) ...[
              const SizedBox(height: 8),
              AvisoError(mensaje: auth.error!),
            ],
            const SizedBox(height: 16),
            FilledButton(
              onPressed: auth.ocupado ? null : _entrar,
              style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
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
    );
  }
}

class _Encabezado extends StatelessWidget {
  const _Encabezado();

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        const FpLogo(tamano: 76),
        const SizedBox(height: 14),
        const Text(
          'Footwear Point',
          textAlign: TextAlign.center,
          style: TextStyle(
            color: Colors.white,
            fontSize: 26,
            fontWeight: FontWeight.bold,
            letterSpacing: -0.4,
          ),
        ),
        const SizedBox(height: 6),
        Text(
          'Inicia sesión para continuar',
          textAlign: TextAlign.center,
          style: TextStyle(color: Colors.white.withValues(alpha: 0.7), fontSize: 15),
        ),
      ],
    );
  }
}

class AvisoError extends StatelessWidget {
  const AvisoError({super.key, required this.mensaje});

  final String mensaje;

  @override
  Widget build(BuildContext context) {
    final colores = Theme.of(context).colorScheme;

    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: colores.errorContainer,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: colores.error.withValues(alpha: 0.2)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(Icons.error_outline, color: colores.onErrorContainer),
          const SizedBox(width: 12),
          Expanded(
            child: Text(mensaje, style: TextStyle(color: colores.onErrorContainer)),
          ),
        ],
      ),
    );
  }
}