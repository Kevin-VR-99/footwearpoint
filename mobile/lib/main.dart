import 'package:firebase_core/firebase_core.dart';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import 'providers/auth_provider.dart';
import 'screens/inicio_screen.dart';
import 'screens/login_screen.dart';

Future<void> main() async {
  // Firebase se conecta al arrancar, leyendo android/app/google-services.json.
  // Todavia NO hay logica de notificaciones: esto solo deja la conexion lista
  // para cuando se agregue el registro del dispositivo (E16-03 / TG-136).
  WidgetsFlutterBinding.ensureInitialized();
  await Firebase.initializeApp();

  runApp(const FootwearPointApp());
}

class FootwearPointApp extends StatelessWidget {
  const FootwearPointApp({super.key});

  @override
  Widget build(BuildContext context) {
    // AuthProvider se crea aquí arriba de todo para que cualquier pantalla
    // pueda preguntarle quién inició sesión. Al crearse, busca si ya había
    // una sesión guardada en el teléfono.
    return ChangeNotifierProvider(
      create: (_) => AuthProvider()..restaurarSesion(),
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
    final haySesion = context.select<AuthProvider, bool>((auth) => auth.haySesion);

    // Mientras se lee la sesión guardada, para que no parpadee el login
    // antes de entrar solo.
    if (iniciando) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }

    if (haySesion) return const InicioScreen();

    // Esto solo se vuelve a construir cuando cambia iniciando o haySesion.
    // Si la sesión se perdió (un 401) con otras pantallas abiertas encima,
    // se cierran para que el login quede a la vista y no escondido debajo.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (context.mounted) {
        Navigator.of(context).popUntil((ruta) => ruta.isFirst);
      }
    });

    return const LoginScreen();
  }
}
