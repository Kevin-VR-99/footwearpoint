import '../models/cliente_privado_revendedor.dart';
import 'api_service.dart';

class ClientePrivadoService {
  ClientePrivadoService({ApiService? api}) : _api = api ?? ApiService();

  final ApiService _api;

  Future<List<ClientePrivadoRevendedor>> listar() async {
    final respuesta = await _api.get('revendedor/clientes-privados');
    final lista = respuesta['data'] as List? ?? [];
    return lista
        .map((e) => ClientePrivadoRevendedor.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  Future<ClientePrivadoRevendedor> crear({
    required String nombre,
    String? telefono,
    String? producto,
    double? monto,
    double? saldo,
    String? referencia,
    String? notas,
  }) async {
    final respuesta = await _api.post(
      'revendedor/clientes-privados',
      cuerpo: {
        'nombre': nombre,
        'telefono': telefono,
        'producto': producto,
        'monto': monto,
        'saldo': saldo,
        'referencia': referencia,
        'notas': notas,
      },
    );
    return ClientePrivadoRevendedor.fromJson(respuesta['data'] as Map<String, dynamic>);
  }

  Future<ClientePrivadoRevendedor> actualizar({
    required int id,
    required String nombre,
    String? telefono,
    String? producto,
    double? monto,
    double? saldo,
    String? referencia,
    String? notas,
  }) async {
    final respuesta = await _api.put(
      'revendedor/clientes-privados/$id',
      cuerpo: {
        'nombre': nombre,
        'telefono': telefono,
        'producto': producto,
        'monto': monto,
        'saldo': saldo,
        'referencia': referencia,
        'notas': notas,
      },
    );
    return ClientePrivadoRevendedor.fromJson(respuesta['data'] as Map<String, dynamic>);
  }

  Future<void> eliminar(int id) async {
    await _api.delete('revendedor/clientes-privados/$id');
  }
}