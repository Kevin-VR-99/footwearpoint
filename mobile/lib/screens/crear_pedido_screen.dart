import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../providers/auth_provider.dart';
import 'package:flutter/services.dart';
import '../models/producto_catalogo.dart';
import '../models/vale.dart';
import '../services/vale_service.dart';

/// Pantalla para registrar un pedido directo y aviso de pago de anticipo,
/// con soporte integrado para aplicar vales vigentes como descuento directo al producto.
class CrearPedidoScreen extends StatefulWidget {
  const CrearPedidoScreen({
    super.key,
    required this.producto,
    required this.variante,
  });

  final ProductoCatalogo producto;
  final VarianteCatalogo variante;

  @override
  State<CrearPedidoScreen> createState() => _CrearPedidoScreenState();
}

class _CrearPedidoScreenState extends State<CrearPedidoScreen> {
  final _formKey = GlobalKey<FormState>();
  // Con el ApiService de AuthProvider (no uno propio): así, si la sesión
  // expira aquí, el 401 regresa al login como en el resto de la app.
  late final ValeService _valeService = ValeService(api: context.read<AuthProvider>().api);
  
  bool _enviando = false;
  bool _cargandoVales = false;
  List<Vale> _valesVigentes = [];
  Vale? _valeSeleccionado;
  
  String _metodoSeleccionado = 'Transferencia';
  final List<String> _metodosPago = ['Transferencia', 'Efectivo', 'Tarjeta', 'Otro'];
  
  late final double _precioOriginal;
  final TextEditingController _referenciaController = TextEditingController();
  final TextEditingController _montoController = TextEditingController();
  final TextEditingController _otroMetodoController = TextEditingController();

  @override
  void initState() {
    super.initState();
    _precioOriginal = widget.producto.precioMinoristaSugerido;
    _actualizarCalculosAnticipo();
    _cargarValesVigentes();
  }

  double get _montoDescuentoVale => _valeSeleccionado?.saldoActual ?? 0.0;
  
  double get _precioNeto {
    final neto = _precioOriginal - _montoDescuentoVale;
    return neto < 0 ? 0.0 : neto;
  }

  double get _anticipoMinimo => _precioNeto * 0.5;

  bool get _cubiertoPorValeCompletamente => _montoDescuentoVale >= _precioOriginal;

  void _actualizarCalculosAnticipo() {
    if (_cubiertoPorValeCompletamente) {
      _montoController.text = '0.00';
    } else {
      _montoController.text = _anticipoMinimo.toStringAsFixed(2);
    }
  }

  Future<void> _cargarValesVigentes() async {
    setState(() => _cargandoVales = true);
    try {
      final vales = await _valeService.listarValesVigentes();
      setState(() {
        _valesVigentes = vales.where((v) => v.saldoActual > 0).toList();
        _cargandoVales = false;
      });
    } catch (e) {
      setState(() => _cargandoVales = false);
    }
  }

  @override
  void dispose() {
    _referenciaController.dispose();
    _montoController.dispose();
    _otroMetodoController.dispose();
    super.dispose();
  }

  String _obtenerEtiquetaReferencia() {
    switch (_metodoSeleccionado) {
      case 'Transferencia':
        return 'Clave de rastreo o Folio SPEI (Solo números, mín. 6 dígitos)';
      case 'Efectivo':
        return 'Número de folio del recibo o ticket de caja';
      case 'Tarjeta':
        return 'Número de autorización del voucher';
      case 'Otro':
        return 'Referencia o comprobante del pago';
      default:
        return 'Referencia / Folio de pago';
    }
  }

  void _enviarAvisoAnticipo() async {
    if (!_cubiertoPorValeCompletamente) {
      if (!_formKey.currentState!.validate()) {
        return;
      }
    }

    setState(() => _enviando = true);

    try {
      await Future.delayed(const Duration(seconds: 1));
      int pedidoIdGenerado = 1; 

      if (_valeSeleccionado != null) {
        final montoADescotarDelVale = _valeSeleccionado!.saldoActual >= _precioOriginal
            ? _precioOriginal
            : _valeSeleccionado!.saldoActual;

        await _valeService.aplicarVale(
          valeId: _valeSeleccionado!.id,
          monto: montoADescotarDelVale,
          pedidoId: pedidoIdGenerado,
        );
      }

      if (!mounted) return;

      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('¡Pedido registrado con éxito!'), backgroundColor: Colors.green),
      );
      Navigator.pop(context);
      Navigator.pop(context);
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Error: ${e.toString()}'), backgroundColor: Colors.red),
      );
    } finally {
      if (mounted) {
        setState(() => _enviando = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final producto = widget.producto;
    final variante = widget.variante;
    final tema = Theme.of(context);

    return Scaffold(
      appBar: AppBar(title: const Text('Pedido Directo y Anticipo')),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16.0),
        child: Form(
          key: _formKey,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(16.0),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        producto.nombre,
                        style: tema.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.bold),
                      ),
                      const SizedBox(height: 8),
                      Text('Modelo: ${producto.modelo}'),
                      Text('Color: ${variante.colorForDisplay ?? variante.color}'),
                      Text('Talla: ${variante.talla}'),
                      const Divider(height: 24),
                      Text(
                        'Precio original: ${_formatearPrecioLocal(_precioOriginal)}',
                        style: tema.textTheme.titleMedium?.copyWith(
                          decoration: _valeSeleccionado != null ? TextDecoration.lineThrough : null,
                          color: _valeSeleccionado != null ? Colors.grey : tema.colorScheme.primary,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                      if (_valeSeleccionado != null) ...[
                        const SizedBox(height: 4),
                        Text(
                          'Descuento por Vale (${_valeSeleccionado!.folio}): -${_formatearPrecioLocal(_montoDescuentoVale)}',
                          style: const TextStyle(color: Colors.green, fontWeight: FontWeight.bold),
                        ),
                        const Divider(height: 16),
                        Text(
                          'Nuevo Total Neto: ${_formatearPrecioLocal(_precioNeto)}',
                          style: tema.textTheme.titleLarge?.copyWith(
                            color: Colors.green.shade700,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                      ],
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 16),

              Card(
                color: tema.colorScheme.surfaceContainerHighest.withValues(alpha: 0.3),
                child: Padding(
                  padding: const EdgeInsets.all(16.0),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        '¿Deseas aplicar un vale a este producto?',
                        style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                      ),
                      const SizedBox(height: 8),
                      _cargandoVales
                          ? const Center(child: LinearProgressIndicator())
                          : _valesVigentes.isEmpty
                              ? const Text('No tienes vales vigentes disponibles.', style: TextStyle(color: Colors.grey))
                              : Row(
                                  children: [
                                    Expanded(
                                      child: DropdownButtonFormField<Vale?>(
                                        value: _valeSeleccionado,
                                        isExpanded: true,
                                        decoration: const InputDecoration(
                                          labelText: 'Seleccionar vale (Opcional)',
                                          border: OutlineInputBorder(),
                                          filled: true,
                                          fillColor: Colors.white,
                                        ),
                                        items: [
                                          const DropdownMenuItem<Vale?>(
                                            value: null,
                                            child: Text(
                                              '-- Ninguno (Sin vale) --',
                                              overflow: TextOverflow.ellipsis,
                                            ),
                                          ),
                                          ..._valesVigentes.map((vale) {
                                            return DropdownMenuItem<Vale?>(
                                              value: vale,
                                              child: Text(
                                                'Folio: ${vale.folio} (Disponible: \$${vale.saldoActual.toStringAsFixed(2)})',
                                                overflow: TextOverflow.ellipsis,
                                              ),
                                            );
                                          }),
                                        ],
                                        onChanged: (vale) {
                                          setState(() {
                                            _valeSeleccionado = vale;
                                            _actualizarCalculosAnticipo();
                                          });
                                        },
                                      ),
                                    ),
                                    if (_valeSeleccionado != null) ...[
                                      const SizedBox(width: 8),
                                      IconButton(
                                        icon: const Icon(Icons.clear, color: Colors.red),
                                        tooltip: 'Quitar vale',
                                        onPressed: () {
                                          setState(() {
                                            _valeSeleccionado = null;
                                            _actualizarCalculosAnticipo();
                                          });
                                        },
                                      ),
                                    ],
                                  ],
                                ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 16),

              if (_cubiertoPorValeCompletamente) ...[
                Card(
                  color: Colors.green.shade50,
                  child: Padding(
                    padding: const EdgeInsets.all(16.0),
                    child: Row(
                      children: [
                        const Icon(Icons.check_circle, color: Colors.green, size: 32),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Text(
                            '¡Excelente! Tu vale cubre el 100% del costo de este producto. No requieres dejar anticipo en efectivo ni transferencia.',
                            style: TextStyle(color: Colors.green.shade900, fontWeight: FontWeight.bold),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ] else ...[
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(16.0),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text(
                          'Registrar Aviso de Anticipo',
                          style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                        ),
                        const SizedBox(height: 12),
                        DropdownButtonFormField<String>(
                          value: _metodoSeleccionado,
                          decoration: const InputDecoration(
                            labelText: 'Método de pago para el resto',
                            border: OutlineInputBorder(),
                          ),
                          items: _metodosPago.map((metodo) {
                            return DropdownMenuItem(value: metodo, child: Text(metodo));
                          }).toList(),
                          onChanged: (val) {
                            if (val != null) {
                              setState(() {
                                _metodoSeleccionado = val;
                                _referenciaController.clear();
                              });
                            }
                          },
                        ),

                        if (_metodoSeleccionado == 'Otro') ...[
                          const SizedBox(height: 12),
                          TextFormField(
                            controller: _otroMetodoController,
                            maxLength: 30,
                            inputFormatters: [
                              FilteringTextInputFormatter.allow(RegExp(r'[a-zA-ZáéíóúÁÉÍÓÚñÑ0-9\s]')),
                            ],
                            decoration: const InputDecoration(
                              labelText: 'Especifique el método de pago',
                              border: OutlineInputBorder(),
                              hintText: 'Ej. Crédito Interno',
                              counterText: '',
                            ),
                            validator: (value) {
                              if (_metodoSeleccionado == 'Otro') {
                                if (value == null || value.trim().isEmpty) {
                                  return 'Debe especificar el método de pago';
                                }
                                if (value.trim().length < 3) {
                                  return 'Especifique un nombre válido (mín. 3 caracteres)';
                                }
                              }
                              return null;
                            },
                          ),
                        ],

                        const SizedBox(height: 12),
                        TextFormField(
                          controller: _montoController,
                          keyboardType: const TextInputType.numberWithOptions(decimal: true),
                          inputFormatters: [
                            FilteringTextInputFormatter.allow(RegExp(r'^\d+\.?\d{0,2}')),
                          ],
                          decoration: InputDecoration(
                            labelText: 'Monto del anticipo (Mín: ${_formatearPrecioLocal(_anticipoMinimo)} - Máx: ${_formatearPrecioLocal(_precioNeto)})',
                            border: const OutlineInputBorder(),
                            prefixText: '\$',
                          ),
                          validator: (value) {
                            if (value == null || value.trim().isEmpty) {
                              return 'El monto es obligatorio';
                            }
                            final monto = double.tryParse(value);
                            if (monto == null) {
                              return 'Ingrese un número válido';
                            }
                            if (monto < _anticipoMinimo) {
                              return 'El anticipo no puede ser menor a ${_formatearPrecioLocal(_anticipoMinimo)}';
                            }
                            if (monto > _precioNeto) {
                              return 'El anticipo no puede ser mayor al precio neto del producto (${_formatearPrecioLocal(_precioNeto)})';
                            }
                            return null;
                          },
                        ),
                        const SizedBox(height: 12),
                        TextFormField(
                          controller: _referenciaController,
                          maxLength: 40,
                          keyboardType: _metodoSeleccionado == 'Transferencia' 
                              ? TextInputType.number 
                              : TextInputType.text,
                          inputFormatters: _metodoSeleccionado == 'Transferencia'
                              ? [FilteringTextInputFormatter.digitsOnly]
                              : [
                                  FilteringTextInputFormatter.allow(RegExp(r'[a-zA-Z0-9\-]')),
                                ],
                          decoration: InputDecoration(
                            labelText: _obtenerEtiquetaReferencia(),
                            border: const OutlineInputBorder(),
                            counterText: '',
                            hintText: _metodoSeleccionado == 'Transferencia' ? 'Ej. 123456' : 'Ej. Folio o recibo',
                          ),
                          validator: (value) {
                            if (value == null || value.trim().isEmpty) {
                              return 'Este campo es obligatorio para validar su pago';
                            }
                            int minLength = _metodoSeleccionado == 'Transferencia' ? 6 : 4;
                            if (value.trim().length < minLength) {
                              if (_metodoSeleccionado == 'Transferencia') {
                                return 'La clave de rastreo debe tener al menos $minLength dígitos numéricos';
                              }
                              return 'Debe ingresar al menos $minLength caracteres válidos';
                            }
                            return null;
                          },
                        ),
                      ],
                    ),
                  ),
                ),
              ],
              const SizedBox(height: 24),
              FilledButton.icon(
                onPressed: _enviando ? null : _enviarAvisoAnticipo,
                icon: _enviando
                    ? const SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                      )
                    : Icon(_cubiertoPorValeCompletamente ? Icons.check_circle_rounded : Icons.send_rounded),
                label: Text(_enviando 
                    ? 'Procesando pedido...' 
                    : (_cubiertoPorValeCompletamente ? 'Finalizar Pedido con Vale' : 'Enviar Aviso de Anticipo')),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Función local para formatear precios sin colisiones
String _formatearPrecioLocal(double precio) {
  final partes = precio.toStringAsFixed(2).split('.');
  final entero = partes[0].replaceAllMapped(
    RegExp(r'\B(?=(\d{3})+(?!\d))'),
    (_) => ',',
  );

  return '\$$entero.${partes[1]}';
}