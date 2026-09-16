/// Configuracion de la app. Un solo lugar para cambiar la direccion del
/// servidor, para no andarla buscando regada entre las pantallas.
class Config {
  /// Direccion base de la API de Laravel.
  ///
  /// Seccion 0.9 del plan del sprint: cada quien corre su PROPIA copia de
  /// Laravel en su propia computadora. Nadie depende de que la maquina de
  /// otro integrante este prendida.
  ///
  /// Que valor usar:
  ///
  ///   - EMULADOR de Android -> dejarlo como esta. La direccion 10.0.2.2 es
  ///     especial: dentro del emulador significa "esta misma computadora".
  ///     No hay que buscar ninguna IP.
  ///
  ///   - CELULAR FISICO -> cambiar 10.0.2.2 por la IP de red local de TU
  ///     computadora, con el celular en la misma WiFi. En Windows se averigua
  ///     con "ipconfig" (busca "Direccion IPv4", algo como 192.168.1.75).
  ///
  /// Y del lado de Laravel, el servidor se levanta asi:
  ///
  ///     php artisan serve --host=0.0.0.0
  ///
  /// Sin el --host, Laravel solo escucha en la propia computadora y el
  /// celular nunca lo va a alcanzar, aunque la IP este bien.
      static const String urlBaseApi = 'http://192.168.1.94:8000/api';
}
