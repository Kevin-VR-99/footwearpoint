import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../models/cliente_privado_revendedor.dart';
import '../providers/auth_provider.dart';
import '../services/api_service.dart';
import '../services/cliente_privado_service.dart';
import 'login_screen.dart';
import 'producto_detalle_screen.dart';

/// Clientes particulares del revendedor (E9-06): gente a la que él le vende
/// por su cuenta. Solo él los ve; el servidor filtra por su revendedor_id.
class ClientesPrivadosScreen extends StatefulWidget {
  const ClientesPrivadosScreen({super.key});

  @override
  State<ClientesPrivadosScreen> createState() => _ClientesPrivadosScreenState();
}

class _ClientesPrivadosScreenState extends State<ClientesPrivadosScreen> {
  // Con el ApiService de AuthProvider (no uno propio): así, si la sesión
  // expira aquí, el 401 regresa al login como en el resto de la app.
  late final _service = ClientePrivadoService(api: context.read<AuthProvider>().api);

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
      _avisar(e.mensaje, esError: true);
    } finally {
      if (mounted) setState(() => _cargando = false);
    }
  }

  Future<void> _abrirFormulario({ClientePrivadoRevendedor? cliente}) async {
    final guardado = await showDialog<bool>(
      context: context,
      builder: (_) => _FormularioClientePrivado(servicio: _service, cliente: cliente),
    );

    if (guardado != true || !mounted) return;

    _avisar(cliente == null ? 'Cliente agregado.' : 'Cliente actualizado.');
    _cargarClientes();
  }

  Future<void> _eliminarCliente(ClientePrivadoRevendedor cliente) async {
    // Antes se borraba con un solo toque, sin preguntar.
    final confirmado = await showDialog<bool>(
      context: context,
      builder: (contexto) => AlertDialog(
        icon: const Icon(Icons.delete_outline),
        title: const Text('¿Eliminar cliente?'),
        content: Text('Se eliminará a ${cliente.nombre} de tus clientes particulares.'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(contexto, false),
            child: const Text('Cancelar'),
          ),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: Theme.of(contexto).colorScheme.error),
            onPressed: () => Navigator.pop(contexto, true),
            child: const Text('Eliminar'),
          ),
        ],
      ),
    );

    if (confirmado != true) return;

    try {
      await _service.eliminar(cliente.id);
      if (!mounted) return;
      _avisar('Cliente eliminado.');
      _cargarClientes();
    } on ApiException catch (e) {
      if (!mounted) return;
      _avisar(e.mensaje, esError: true);
    }
  }

  void _avisar(String mensaje, {bool esError = false}) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(mensaje),
        backgroundColor: esError ? Theme.of(context).colorScheme.error : null,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Mis clientes particulares')),
      body: SafeArea(child: _contenido()),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _cargando ? null : () => _abrirFormulario(),
        icon: const Icon(Icons.person_add_alt_1),
        label: const Text('Agregar cliente'),
      ),
    );
  }

  Widget _contenido() {
    if (_cargando) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_clientes.isEmpty) {
      final tema = Theme.of(context);

      return Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(Icons.people_outline, size: 64, color: tema.colorScheme.onSurfaceVariant),
              const SizedBox(height: 16),
              Text('Todavía no tienes clientes particulares', style: tema.textTheme.titleMedium),
              const SizedBox(height: 8),
              Text(
                'Toca "Agregar cliente" para registrar a quien le vendes por tu cuenta.',
                textAlign: TextAlign.center,
                style: tema.textTheme.bodyMedium?.copyWith(color: tema.colorScheme.onSurfaceVariant),
              ),
            ],
          ),
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: _cargarClientes,
      child: ListView.separated(
        // Espacio abajo para que el botón flotante no tape la última tarjeta.
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 96),
        itemCount: _clientes.length,
        separatorBuilder: (_, _) => const SizedBox(height: 12),
        itemBuilder: (_, i) => _TarjetaCliente(
          cliente: _clientes[i],
          alEditar: () => _abrirFormulario(cliente: _clientes[i]),
          alEliminar: () => _eliminarCliente(_clientes[i]),
        ),
      ),
    );
  }
}

class _TarjetaCliente extends StatelessWidget {
  const _TarjetaCliente({
    required this.cliente,
    required this.alEditar,
    required this.alEliminar,
  });

  final ClientePrivadoRevendedor cliente;
  final VoidCallback alEditar;
  final VoidCallback alEliminar;

  bool _tiene(String? texto) => texto != null && texto.trim().isNotEmpty;

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);
    final colores = tema.colorScheme;
    final saldo = cliente.saldo ?? 0;
    final debe = saldo > 0;

    return Card(
      margin: EdgeInsets.zero,
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: alEditar,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 4, 12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  CircleAvatar(
                    backgroundColor: colores.primaryContainer,
                    foregroundColor: colores.onPrimaryContainer,
                    child: Text(cliente.nombre.trim().isEmpty ? '?' : cliente.nombre.trim()[0].toUpperCase()),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          cliente.nombre,
                          style: tema.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w600),
                        ),
                        if (_tiene(cliente.telefono))
                          Text(cliente.telefono!, style: tema.textTheme.bodySmall),
                      ],
                    ),
                  ),
                  IconButton(
                    tooltip: 'Editar',
                    icon: const Icon(Icons.edit_outlined),
                    onPressed: alEditar,
                  ),
                  IconButton(
                    tooltip: 'Eliminar',
                    icon: Icon(Icons.delete_outline, color: colores.error),
                    onPressed: alEliminar,
                  ),
                ],
              ),
              if (_tiene(cliente.producto)) ...[
                const SizedBox(height: 8),
                Row(
                  children: [
                    Icon(Icons.shopping_bag_outlined, size: 18, color: colores.onSurfaceVariant),
                    const SizedBox(width: 6),
                    Expanded(child: Text(cliente.producto!)),
                  ],
                ),
              ],
              // Sin importes (los dos en 0) no se muestra la fila.
              if ((cliente.monto ?? 0) > 0 || saldo > 0) ...[
                const SizedBox(height: 10),
                Padding(
                  padding: const EdgeInsets.only(right: 12),
                  child: Row(
                    children: [
                      Expanded(
                        child: _Importe(etiqueta: 'Monto', valor: cliente.monto ?? 0),
                      ),
                      Expanded(
                        child: _Importe(
                          etiqueta: debe ? 'Saldo pendiente' : 'Saldo',
                          valor: saldo,
                          resaltar: debe,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
              if (_tiene(cliente.referencia) || _tiene(cliente.notas)) ...[
                const SizedBox(height: 8),
                Text(
                  [
                    if (_tiene(cliente.referencia)) cliente.referencia!,
                    if (_tiene(cliente.notas)) cliente.notas!,
                  ].join(' · '),
                  style: tema.textTheme.bodySmall?.copyWith(color: colores.onSurfaceVariant),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class _Importe extends StatelessWidget {
  const _Importe({required this.etiqueta, required this.valor, this.resaltar = false});

  final String etiqueta;
  final double valor;
  final bool resaltar;

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(etiqueta, style: tema.textTheme.labelSmall?.copyWith(color: tema.colorScheme.onSurfaceVariant)),
        Text(
          formatoPrecio(valor),
          style: tema.textTheme.titleSmall?.copyWith(
            fontWeight: FontWeight.w600,
            color: resaltar ? tema.colorScheme.error : null,
          ),
        ),
      ],
    );
  }
}

/// El formulario para agregar o editar un cliente particular.
///
/// Es su propio widget para poder mostrar "guardando" en el botón, el error
/// dentro del mismo formulario, y liberar sus campos de texto al cerrarse.
/// Regresa true si se guardó.
class _FormularioClientePrivado extends StatefulWidget {
  const _FormularioClientePrivado({required this.servicio, this.cliente});

  final ClientePrivadoService servicio;
  final ClientePrivadoRevendedor? cliente;

  @override
  State<_FormularioClientePrivado> createState() => _FormularioClientePrivadoState();
}

class _FormularioClientePrivadoState extends State<_FormularioClientePrivado> {
  final _formulario = GlobalKey<FormState>();

  late final _nombre = TextEditingController(text: widget.cliente?.nombre ?? '');
  late final _telefono = TextEditingController(text: widget.cliente?.telefono ?? '');
  late final _producto = TextEditingController(text: widget.cliente?.producto ?? '');
  late final _monto = TextEditingController(text: _textoImporte(widget.cliente?.monto));
  late final _saldo = TextEditingController(text: _textoImporte(widget.cliente?.saldo));
  late final _referencia = TextEditingController(text: widget.cliente?.referencia ?? '');
  late final _notas = TextEditingController(text: widget.cliente?.notas ?? '');

  bool _guardando = false;
  String? _error;

  bool get _esNuevo => widget.cliente == null;

  @override
  void dispose() {
    for (final campo in [_nombre, _telefono, _producto, _monto, _saldo, _referencia, _notas]) {
      campo.dispose();
    }
    super.dispose();
  }

  /// 1200.0 -> "1200", 1200.5 -> "1200.50": sin el ".0" que antes se veía al editar.
  static String _textoImporte(double? valor) {
    if (valor == null) return '';
    return valor == valor.roundToDouble() ? valor.toStringAsFixed(0) : valor.toStringAsFixed(2);
  }

  static String? _validarImporte(String? valor) {
    final texto = valor?.trim() ?? '';
    if (texto.isEmpty) return null;

    final numero = double.tryParse(texto);
    if (numero == null) return 'Escribe solo números.';
    if (numero < 0) return 'No puede ser negativo.';
    return null;
  }

  Future<void> _guardar() async {
    if (!_formulario.currentState!.validate()) return;

    FocusScope.of(context).unfocus();
    setState(() {
      _guardando = true;
      _error = null;
    });

    String? texto(TextEditingController campo) =>
        campo.text.trim().isEmpty ? null : campo.text.trim();

    // Vacío se manda como 0, el mismo valor por defecto de la tabla: con null
    // el servidor respondía error 500, porque monto y saldo no admiten vacío.
    double importe(TextEditingController campo) => double.tryParse(campo.text.trim()) ?? 0;

    try {
      if (_esNuevo) {
        await widget.servicio.crear(
          nombre: _nombre.text.trim(),
          telefono: texto(_telefono),
          producto: texto(_producto),
          monto: importe(_monto),
          saldo: importe(_saldo),
          referencia: texto(_referencia),
          notas: texto(_notas),
        );
      } else {
        await widget.servicio.actualizar(
          id: widget.cliente!.id,
          nombre: _nombre.text.trim(),
          telefono: texto(_telefono),
          producto: texto(_producto),
          monto: importe(_monto),
          saldo: importe(_saldo),
          referencia: texto(_referencia),
          notas: texto(_notas),
        );
      }

      // Pudo cerrarse tocando fuera mientras se guardaba.
      if (mounted) Navigator.pop(context, true);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _guardando = false;
        _error = e.errores.values.isNotEmpty ? e.errores.values.first.first : e.mensaje;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);

    return Dialog(
      insetPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 24),
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 480),
        child: Form(
          key: _formulario,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              // Encabezado
              Padding(
                padding: const EdgeInsets.fromLTRB(24, 24, 24, 8),
                child: Row(
                  children: [
                    CircleAvatar(
                      backgroundColor: tema.colorScheme.primaryContainer,
                      foregroundColor: tema.colorScheme.onPrimaryContainer,
                      child: Icon(_esNuevo ? Icons.person_add_alt_1 : Icons.edit_outlined),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            _esNuevo ? 'Nuevo cliente particular' : 'Editar cliente',
                            style: tema.textTheme.titleLarge,
                          ),
                          Text(
                            'Solo tú puedes ver a tus clientes particulares.',
                            style: tema.textTheme.bodySmall?.copyWith(
                              color: tema.colorScheme.onSurfaceVariant,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),

              // Campos
              Flexible(
                child: SingleChildScrollView(
                  padding: const EdgeInsets.fromLTRB(24, 8, 24, 8),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      const _Seccion('Contacto'),
                      // Los máximos son los de la tabla clientes_privados_revendedor:
                      // pasarse hacía que el servidor respondiera con error 500.
                      _Campo(
                        controller: _nombre,
                        etiqueta: 'Nombre *',
                        icono: Icons.person_outline,
                        maximo: 150,
                        habilitado: !_guardando,
                        mayusculas: TextCapitalization.words,
                        validator: (v) =>
                            (v == null || v.trim().isEmpty) ? 'Escribe el nombre del cliente.' : null,
                      ),
                      _Campo(
                        controller: _telefono,
                        etiqueta: 'Teléfono',
                        icono: Icons.phone_outlined,
                        maximo: 30,
                        habilitado: !_guardando,
                        teclado: TextInputType.phone,
                      ),
                      const _Seccion('Venta'),
                      _Campo(
                        controller: _producto,
                        etiqueta: 'Producto',
                        icono: Icons.shopping_bag_outlined,
                        maximo: 190,
                        habilitado: !_guardando,
                      ),
                      Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Expanded(
                            child: _Campo(
                              controller: _monto,
                              etiqueta: 'Monto',
                              prefijo: '\$ ',
                              habilitado: !_guardando,
                              teclado: const TextInputType.numberWithOptions(decimal: true),
                              soloImporte: true,
                              validator: _validarImporte,
                            ),
                          ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: _Campo(
                              controller: _saldo,
                              etiqueta: 'Saldo',
                              prefijo: '\$ ',
                              ayuda: 'Lo que aún te debe',
                              habilitado: !_guardando,
                              teclado: const TextInputType.numberWithOptions(decimal: true),
                              soloImporte: true,
                              validator: _validarImporte,
                            ),
                          ),
                        ],
                      ),
                      const _Seccion('Otros datos'),
                      _Campo(
                        controller: _referencia,
                        etiqueta: 'Referencia',
                        icono: Icons.place_outlined,
                        ayuda: 'Por ejemplo, dónde vive o quién te lo recomendó',
                        maximo: 150,
                        habilitado: !_guardando,
                      ),
                      _Campo(
                        controller: _notas,
                        etiqueta: 'Notas',
                        icono: Icons.notes_outlined,
                        habilitado: !_guardando,
                        lineas: 3,
                      ),
                      if (_error != null) ...[
                        const SizedBox(height: 8),
                        AvisoError(mensaje: _error!),
                      ],
                    ],
                  ),
                ),
              ),

              // Botones
              Padding(
                padding: const EdgeInsets.fromLTRB(24, 8, 24, 20),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.end,
                  children: [
                    TextButton(
                      onPressed: _guardando ? null : () => Navigator.pop(context, false),
                      child: const Text('Cancelar'),
                    ),
                    const SizedBox(width: 8),
                    FilledButton(
                      onPressed: _guardando ? null : _guardar,
                      child: _guardando
                          ? const SizedBox(
                              height: 20,
                              width: 20,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            )
                          : const Text('Guardar'),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Seccion extends StatelessWidget {
  const _Seccion(this.titulo);

  final String titulo;

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.only(top: 12, bottom: 8),
      child: Text(
        titulo.toUpperCase(),
        style: tema.textTheme.labelSmall?.copyWith(
          color: tema.colorScheme.primary,
          fontWeight: FontWeight.w700,
          letterSpacing: 0.8,
        ),
      ),
    );
  }
}

class _Campo extends StatelessWidget {
  const _Campo({
    required this.controller,
    required this.etiqueta,
    required this.habilitado,
    this.icono,
    this.prefijo,
    this.ayuda,
    this.maximo,
    this.lineas = 1,
    this.teclado,
    this.mayusculas = TextCapitalization.sentences,
    this.soloImporte = false,
    this.validator,
  });

  final TextEditingController controller;
  final String etiqueta;
  final bool habilitado;
  final IconData? icono;
  final String? prefijo;
  final String? ayuda;
  final int? maximo;
  final int lineas;
  final TextInputType? teclado;
  final TextCapitalization mayusculas;
  final bool soloImporte;
  final FormFieldValidator<String>? validator;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: TextFormField(
        controller: controller,
        enabled: habilitado,
        maxLength: maximo,
        maxLines: lineas,
        keyboardType: teclado,
        textCapitalization: mayusculas,
        validator: validator,
        inputFormatters: soloImporte
            ? [FilteringTextInputFormatter.allow(RegExp(r'[0-9.]'))]
            : null,
        decoration: InputDecoration(
          labelText: etiqueta,
          prefixIcon: icono != null ? Icon(icono) : null,
          prefixText: prefijo,
          helperText: ayuda,
          border: const OutlineInputBorder(),
          isDense: true,
          // El límite se respeta, pero sin mostrar "0/150" en cada campo.
          counterText: '',
        ),
      ),
    );
  }
}
