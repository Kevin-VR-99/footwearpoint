import 'package:flutter/material.dart';
import '../models/cliente_privado_revendedor.dart';
import '../services/cliente_privado_service.dart';
import '../services/api_service.dart';

class ClientesPrivadosScreen extends StatefulWidget {
  const ClientesPrivadosScreen({super.key});

  @override
  State<ClientesPrivadosScreen> createState() => _ClientesPrivadosScreenState();
}

class _ClientesPrivadosScreenState extends State<ClientesPrivadosScreen> {
  final _service = ClientePrivadoService();
  bool _cargando = true;
  List<ClientePrivadoRevendedor> _clientes = [];

  @override
  void initState() {
    super.initState();
    _cargarClientes();
  }

  Future<void> _cargarClientes() async {
    setState(() => _cargando = true);
    // Después de cada await se revisa `mounted`: si la persona salió de la
    // pantalla mientras el servidor contestaba, ya no se debe tocar (tronaba).
    try {
      final lista = await _service.listar();
      if (!mounted) return;
      setState(() => _clientes = lista);
    } on ApiException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.mensaje), backgroundColor: Colors.red),
      );
    } finally {
      if (mounted) setState(() => _cargando = false);
    }
  }

  void _abrirFormulario({ClientePrivadoRevendedor? cliente}) {
    final nombreController = TextEditingController(text: cliente?.nombre ?? '');
    final telefonoController = TextEditingController(text: cliente?.telefono ?? '');
    final productoController = TextEditingController(text: cliente?.producto ?? '');
    final montoController = TextEditingController(text: cliente?.monto?.toString() ?? '');
    final saldoController = TextEditingController(text: cliente?.saldo?.toString() ?? '');
    final referenciaController = TextEditingController(text: cliente?.referencia ?? '');
    final notasController = TextEditingController(text: cliente?.notas ?? '');
    final formKey = GlobalKey<FormState>();

    showDialog(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(cliente == null ? 'Nuevo Cliente Particular' : 'Editar Cliente'),
        content: Form(
          key: formKey,
          child: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                TextFormField(
                  controller: nombreController,
                  decoration: const InputDecoration(labelText: 'Nombre *'),
                  validator: (v) => v == null || v.isEmpty ? 'El nombre es obligatorio' : null,
                ),
                TextFormField(
                  controller: telefonoController,
                  decoration: const InputDecoration(labelText: 'Teléfono'),
                  keyboardType: TextInputType.phone,
                ),
                TextFormField(
                  controller: productoController,
                  decoration: const InputDecoration(labelText: 'Producto'),
                ),
                TextFormField(
                  controller: montoController,
                  decoration: const InputDecoration(labelText: 'Monto'),
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                ),
                TextFormField(
                  controller: saldoController,
                  decoration: const InputDecoration(labelText: 'Saldo'),
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                ),
                TextFormField(
                  controller: referenciaController,
                  decoration: const InputDecoration(labelText: 'Referencia'),
                ),
                TextFormField(
                  controller: notasController,
                  decoration: const InputDecoration(labelText: 'Notas'),
                  maxLines: 2,
                ),
              ],
            ),
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('Cancelar'),
          ),
          ElevatedButton(
            onPressed: () async {
              if (!formKey.currentState!.validate()) return;
              try {
                final monto = double.tryParse(montoController.text);
                final saldo = double.tryParse(saldoController.text);

                if (cliente == null) {
                  await _service.crear(
                    nombre: nombreController.text,
                    telefono: telefonoController.text,
                    producto: productoController.text,
                    monto: monto,
                    saldo: saldo,
                    referencia: referenciaController.text,
                    notas: notasController.text,
                  );
                } else {
                  await _service.actualizar(
                    id: cliente.id,
                    nombre: nombreController.text,
                    telefono: telefonoController.text,
                    producto: productoController.text,
                    monto: monto,
                    saldo: saldo,
                    referencia: referenciaController.text,
                    notas: notasController.text,
                  );
                }
                // Aquí `context` es el del diálogo: pudo cerrarse tocando fuera
                // mientras se guardaba. `mounted` es el de la pantalla.
                if (context.mounted) Navigator.pop(context);
                if (mounted) _cargarClientes();
              } on ApiException catch (e) {
                if (!context.mounted) return;
                ScaffoldMessenger.of(context).showSnackBar(
                  SnackBar(content: Text(e.mensaje), backgroundColor: Colors.red),
                );
              }
            },
            child: const Text('Guardar'),
          ),
        ],
      ),
    );
  }

  Future<void> _eliminarCliente(int id) async {
    try {
      await _service.eliminar(id);
      if (mounted) _cargarClientes();
    } on ApiException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.mensaje), backgroundColor: Colors.red),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Mis Clientes Particulares'),
      ),
      body: _cargando
          ? const Center(child: CircularProgressIndicator())
          : _clientes.isEmpty
              ? const Center(
                  child: Text('No tienes clientes particulares registrados.'),
                )
              : ListView.builder(
                  itemCount: _clientes.length,
                  itemBuilder: (context, index) {
                    final c = _clientes[index];
                    return Card(
                      margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                      child: ListTile(
                        title: Text(c.nombre, style: const TextStyle(fontWeight: FontWeight.bold)),
                        subtitle: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            if (c.producto != null && c.producto!.isNotEmpty)
                              Text('Producto: ${c.producto}'),
                            if (c.monto != null) Text('Monto: \$${c.monto}'),
                            if (c.saldo != null) Text('Saldo: \$${c.saldo}'),
                            if (c.telefono != null && c.telefono!.isNotEmpty)
                              Text('Tel: ${c.telefono}'),
                          ],
                        ),
                        trailing: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            IconButton(
                              icon: const Icon(Icons.edit, color: Colors.blue),
                              onPressed: () => _abrirFormulario(cliente: c),
                            ),
                            IconButton(
                              icon: const Icon(Icons.delete, color: Colors.red),
                              onPressed: () => _eliminarCliente(c.id),
                            ),
                          ],
                        ),
                      ),
                    );
                  },
                ),
      floatingActionButton: FloatingActionButton(
        onPressed: () => _abrirFormulario(),
        child: const Icon(Icons.add),
      ),
    );
  }
}