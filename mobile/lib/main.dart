import 'package:firebase_core/firebase_core.dart';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import 'providers/auth_provider.dart';
import 'screens/inicio_screen.dart';
import 'screens/login_screen.dart';
import 'services/api_service.dart';

Future<void> main() async {
  // Firebase se conecta al arrancar, leyendo android/app/google-services.json.
  // Las notificaciones push ya usan esta conexion: AuthProvider registra el
  // celular en Laravel al iniciar sesion y al abrir la app (E16-03), y el
  // servidor lo quita al cerrar la sesion (TG-144).
  WidgetsFlutterBinding.ensureInitialized();
  await Firebase.initializeApp();

  runApp(const FootwearPointApp());
}

class FootwearPointApp extends StatelessWidget {
  const FootwearPointApp({super.key, this.api});

  /// Solo para las pruebas, que le pasan un servidor falso. En la app real
  /// no se manda y se usa el ApiService normal.
  final ApiService? api;

  @override
  Widget build(BuildContext context) {
    // AuthProvider se crea aquí arriba de todo para que cualquier pantalla
    // pueda preguntarle quién inició sesión. Al crearse, revisa si el token
    // guardado en el teléfono todavía sirve.
    return ChangeNotifierProvider(
      create: (_) => AuthProvider(api: api)..restaurarSesion(),
      child: MaterialApp(
        title: 'FootwearPoint',
        debugShowCheckedModeBanner: false,
        theme: ThemeData(
          colorSchemeSeed: const Color(0xFF6D4C41),
          useMaterial3: true,
        ),
        home: const _Raiz(),
      ),
    );
  }
}

/// Decide qué pantalla mostrar según si hay sesión o no.
class _Raiz extends StatelessWidget {
  const _Raiz();

  @override
  Widget build(BuildContext context) {
    final iniciando = context.select<AuthProvider, bool>((auth) => auth.iniciando);
    final sinConexion = context.select<AuthProvider, bool>((auth) => auth.sinConexion);
    final haySesion = context.select<AuthProvider, bool>((auth) => auth.haySesion);
    final sinAcceso = context.select<AuthProvider, bool>((auth) => auth.sinAcceso);

    // Mientras se revisa el token guardado, para que no parpadee el login
    // antes de entrar solo.
    if (iniciando) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }

    if (sinConexion) return const _SinConexionScreen();

    // Va antes que la pantalla de inicio: sin distribuidora no se entra.
    if (sinAcceso) return const _SinAccesoScreen();

    if (haySesion) return const InicioScreen();

    // Esto solo se vuelve a construir cuando cambia alguno de los valores
    // de arriba. Si la sesión se perdió (un 401) con otras pantallas
    // abiertas encima, se cierran para que el login quede a la vista y no
    // escondido debajo.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (context.mounted) {
        Navigator.of(context).popUntil((ruta) => ruta.isFirst);
      }
    });

    return const LoginScreen();
  }
}

/// Había sesión guardada, pero al abrir la app no se pudo hablar con el
/// servidor para revisarla. No se manda al login: puede ser solo que no hay
/// conexión, y la sesión sigue siendo buena.
class _SinConexionScreen extends StatelessWidget {
  const _SinConexionScreen();

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();

    return _AvisoScreen(
      icono: Icons.wifi_off_rounded,
      titulo: 'No se pudo conectar con el servidor',
      texto: auth.error,
      acciones: [
        FilledButton.icon(
          onPressed: auth.reintentar,
          style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
          icon: const Icon(Icons.refresh),
          label: const Text('Reintentar'),
        ),
      ],
    );
  }
}

/// La cuenta es válida, pero no tiene distribuidora (afiliación suspendida
/// o cuenta mal ligada). Ver AuthProvider.sinAcceso.
class _SinAccesoScreen extends StatelessWidget {
  const _SinAccesoScreen();

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();

    return _AvisoScreen(
      icono: Icons.lock_person_outlined,
      titulo: 'Sin acceso por ahora',
      texto: 'Tu cuenta no tiene acceso activo a ninguna distribuidora. '
          'Comunícate con tu distribuidora para que lo revise.',
      acciones: [
        // Vuelve a preguntarle a auth/me: si ya la reactivaron, entra.
        FilledButton.icon(
          onPressed: auth.ocupado ? null : auth.reintentar,
          style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
          icon: const Icon(Icons.refresh),
          label: const Text('Volver a revisar'),
        ),
        const SizedBox(height: 12),
        OutlinedButton(
          onPressed: auth.ocupado ? null : auth.logout,
          style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(52)),
          child: const Text('Cerrar sesión'),
        ),
      ],
    );
  }
}

/// Molde de las pantallas de aviso de pantalla completa: ícono, título,
/// texto opcional y botones.
class _AvisoScreen extends StatelessWidget {
  const _AvisoScreen({
    required this.icono,
    required this.titulo,
    required this.acciones,
    this.texto,
  });

  final IconData icono;
  final String titulo;
  final String? texto;
  final List<Widget> acciones;

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);

    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Icon(icono, size: 64, color: tema.colorScheme.error),
                  const SizedBox(height: 16),
                  Text(
                    titulo,
                    textAlign: TextAlign.center,
                    style: tema.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.bold),
                  ),
                  if (texto != null) ...[
                    const SizedBox(height: 12),
                    Text(
                      texto!,
                      textAlign: TextAlign.center,
                      style: tema.textTheme.bodyMedium?.copyWith(
                        color: tema.colorScheme.onSurfaceVariant,
                      ),
                    ),
                  ],
                  const SizedBox(height: 24),
                  ...acciones,
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
