import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../providers/auth_provider.dart';
import '../tema/fp_colores.dart';
import '../validaciones.dart';
import '../widgets/fp_componentes.dart';
import 'recuperar_password_screen.dart';

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

  // No se exige mínimo de caracteres aquí: el login del backend no lo pide,
  // y ponerlo podría dejar fuera a cuentas que ya existen. El mínimo de 8 se
  // aplica al crear o cambiar la contraseña, no al entrar.
  String? _validarPassword(String? valor) {
    return (valor == null || valor.isEmpty) ? 'Escribe tu contraseña.' : null;
  }

  Future<void> _entrar() async {
    if (!_formulario.currentState!.validate()) return;

    FocusScope.of(context).unfocus();

    final entro = await context.read<AuthProvider>().login(_email.text.trim(), _password.text);

    // Le avisa al teléfono que el login funcionó, para que ofrezca guardar
    // la contraseña en su gestor (si el usuario tiene uno).
    if (entro) TextInput.finishAutofillContext();
  }

  void _abrirRecuperarPassword() {
    Navigator.of(context).push(
      MaterialPageRoute<void>(
        // Se lleva el correo que ya escribió, para no hacerlo escribir dos veces.
        builder: (_) => RecuperarPasswordScreen(correoInicial: _email.text.trim()),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();

    // Diseño (TG-165): fondo azul marino como el menú de la web, con el logo
    // arriba y el formulario en una tarjeta blanca. Mismos campos y botones.
    // Íconos blancos en la barra de estado del teléfono, sobre el fondo oscuro.
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
                        // En tabletas o celulares acostados no se estira de lado a lado.
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

/// Logo circular y nombre, como la marca del menú de la web.
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

/// Recuadro rojo con un mensaje de error: por qué no se pudo entrar
/// (credenciales, cuenta inactiva, sin conexión), el aviso de que la sesión
/// expiró, o por qué no se pudo mandar el enlace de recuperación.
///
/// Es público porque también lo usa RecuperarPasswordScreen.
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
