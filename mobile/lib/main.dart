import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import 'providers/auth_provider.dart';
import 'screens/inicio_screen.dart';
import 'screens/login_screen.dart';

void main() {
  runApp(const FootwearPointApp());
}

class FootwearPointApp extends StatelessWidget {
  const FootwearPointApp({super.key});

  @override
  Widget build(BuildContext context) {
    // AuthProvider se crea aquí arriba de todo para que cualquier pantalla
    // pueda preguntarle quién inició sesión.
    return ChangeNotifierProvider(
      create: (_) => AuthProvider(),
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
    final haySesion = context.select<AuthProvider, bool>((auth) => auth.haySesion);

    return haySesion ? const InicioScreen() : const LoginScreen();
  }
}
