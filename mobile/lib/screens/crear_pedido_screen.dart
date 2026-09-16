import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../models/producto_catalogo.dart';

/// Pantalla para registrar un pedido directo bajo pedido y aviso de pago de anticipo (E8-01 / E8-02).
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
  bool _enviando = false;
  
  String _metodoSeleccionado = 'Transferencia';
  final List<String> _metodosPago = ['Transferencia', 'Efectivo', 'Tarjeta', 'Otro'];
  
  late final double _anticipoMinimo;
  late final double _precioMaximo;
  final TextEditingController _referenciaController = TextEditingController();
  final TextEditingController _montoController = TextEditingController();
  final TextEditingController _otroMetodoController = TextEditingController();

  @override
  void initState() {
    super.initState();
    _precioMaximo = widget.producto.precioMinoristaSugerido;
    // Anticipo mínimo obligatorio (ej. 50% del precio sugerido)
    _anticipoMinimo = _precioMaximo * 0.5;
    _montoController.text = _anticipoMinimo.toStringAsFixed(2);
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
    if (!_formKey.currentState!.validate()) {
      return;
    }

    setState(() => _enviando = true);

    // Simulamos la llamada al backend para registrar el aviso pendiente
    await Future.delayed(const Duration(seconds: 2));

    if (!mounted) return;

    setState(() => _enviando = false);

    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('¡Aviso de anticipo registrado como pendiente con éxito!')),
    );
    Navigator.pop(context); // Regresa al detalle
    Navigator.pop(context); // Regresa al catálogo
  }

  @override
  Widget build(BuildContext context) {
    final producto = widget.producto;
    final variante = widget.variante;
    final tema = Theme.of(context);

    return Scaffold(
      appBar: AppBar(title: const Text('Aviso de Anticipo - Pedido Directo')),
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
                      Text('Color: ${variante.color}'),
                      Text('Talla: ${variante.talla}'),
                      const Divider(height: 24),
                      Text(
                        'Precio sugerido: ${formatoPrecio(_precioMaximo)}',
                        style: tema.textTheme.titleMedium?.copyWith(
                          color: tema.colorScheme.primary,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 16),
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(16.0),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        'Registrar Aviso de Anticipo (E8-02)',
                        style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                      ),
                      const SizedBox(height: 12),
                      DropdownButtonFormField<String>(
                        value: _metodoSeleccionado,
                        decoration: const InputDecoration(
                          labelText: 'Método de pago',
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
                          maxLength: 30, // Límite estricto de longitud
                          inputFormatters: [
                            // Solo permite letras, números y espacios (bloquea símbolos especiales y emojis)
                            FilteringTextInputFormatter.allow(RegExp(r'[a-zA-ZáéíóúÁÉÍÓÚñÑ0-9\s]')),
                          ],
                          decoration: const InputDecoration(
                            labelText: 'Especifique el método de pago',
                            border: OutlineInputBorder(),
                            hintText: 'Ej. Crédito Interno',
                            counterText: '', // Oculta el contador visual si se prefiere limpio
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
                          labelText: 'Monto del anticipo (Mín: ${formatoPrecio(_anticipoMinimo)} - Máx: ${formatoPrecio(_precioMaximo)})',
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
                            return 'El anticipo no puede ser menor a ${formatoPrecio(_anticipoMinimo)}';
                          }
                          if (monto > _precioMaximo) {
                            return 'El anticipo no puede ser mayor al precio total del producto (${formatoPrecio(_precioMaximo)})';
                          }
                          return null;
                        },
                      ),
                      const SizedBox(height: 12),
                      TextFormField(
                        controller: _referenciaController,
                        maxLength: 40, // Límite máximo para evitar cadenas infinitas
                        keyboardType: _metodoSeleccionado == 'Transferencia' 
                            ? TextInputType.number 
                            : TextInputType.text,
                        inputFormatters: _metodoSeleccionado == 'Transferencia'
                            ? [FilteringTextInputFormatter.digitsOnly] // Estrictamente dígitos numéricos
                            : [
                                // Para otros métodos, permitimos alfanuméricos y guiones comunes en folios
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
              const SizedBox(height: 24),
              FilledButton.icon(
                onPressed: _enviando ? null : _enviarAvisoAnticipo,
                icon: _enviando
                    ? const SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                      )
                    : const Icon(Icons.send_rounded),
                label: Text(_enviando ? 'Enviando aviso...' : 'Enviar Aviso de Anticipo'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// 1600 -> "$1,600.00"
String formatoPrecio(double precio) {
  final partes = precio.toStringAsFixed(2).split('.');
  final entero = partes[0].replaceAllMapped(
    RegExp(r'\B(?=(\d{3})+(?!\d))'),
    (_) => ',',
  );

  return '\$$entero.${partes[1]}';
}