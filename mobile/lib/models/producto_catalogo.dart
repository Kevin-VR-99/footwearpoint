/// Lo que regresa GET /api/catalogo (E4-05).
///
/// Los nombres de los campos salen tal cual de
/// App\Http\Resources\Catalogo\CatalogoResource y de docs/contrato-api.md
/// (sección 3). No se inventó ninguno.

/// Disponibilidad de una variante en la campaña: columna `estado` de
/// disponibilidad_variante_campana.
enum Disponibilidad {
  disponible('Disponible'),
  bajoPedido('Bajo pedido'),
  noDisponible('No disponible');

  const Disponibilidad(this.etiqueta);

  /// Texto para mostrar en pantalla.
  final String etiqueta;

  static Disponibilidad desdeJson(String valor) {
    return switch (valor) {
      'disponible' => Disponibilidad.disponible,
      'bajo_pedido' => Disponibilidad.bajoPedido,
      'no_disponible' => Disponibilidad.noDisponible,
      _ => Disponibilidad.noDisponible,
    };
  }
}

/// Marca, línea o categoría: el backend las manda igual, { id, nombre }.
class Referencia {
  const Referencia({required this.id, required this.nombre});

  final int id;
  final String nombre;

  static Referencia? desdeJson(Object? json) {
    if (json is! Map<String, dynamic>) return null;

    return Referencia(id: json['id'] as int, nombre: json['nombre'] as String);
  }
}

class ImagenCatalogo {
  const ImagenCatalogo({
    required this.id,
    required this.url,
    required this.orden,
    required this.esPrincipal,
  });

  final int id;
  final String url;
  final int orden;
  final bool esPrincipal;

  factory ImagenCatalogo.desdeJson(Map<String, dynamic> json) {
    return ImagenCatalogo(
      id: json['id'] as int,
      url: json['url'] as String,
      orden: json['orden'] as int,
      esPrincipal: json['es_principal'] as bool,
    );
  }
}

class VarianteCatalogo {
  const VarianteCatalogo({
    required this.varianteId,
    required this.sku,
    required this.talla,
    required this.color,
    required this.disponibilidad,
    this.nombreColorComercial,
  });

  final int varianteId;
  final String sku;
  final String talla;
  final String color;

  /// Nombre de venta del color (por ejemplo "Negro humo"). Puede no venir.
  final String? nombreColorComercial;

  final Disponibilidad disponibilidad;

  /// El nombre comercial si hay; si no, el color base.
  String get colorParaMostrar => nombreColorComercial ?? color;
  String? get colorForDisplay => nombreColorComercial ?? color;

  factory VarianteCatalogo.desdeJson(Map<String, dynamic> json) {
    return VarianteCatalogo(
      varianteId: json['variante_id'] as int,
      sku: json['sku'] as String,
      talla: json['talla'].toString(),
      color: json['color'] as String,
      nombreColorComercial: json['nombre_color_comercial'] as String?,
      disponibilidad: Disponibilidad.desdeJson(json['disponibilidad'] as String),
    );
  }
}

/// Un producto publicado en una campaña activa.
class ProductoCatalogo {
  const ProductoCatalogo({
    required this.id,
    required this.productoId,
    required this.modelo,
    required this.nombre,
    required this.codigoCatalogo,
    required this.precioMinoristaSugerido,
    required this.imagenes,
    required this.variantes,
    this.marca,
    this.linea,
    this.categoria,
    this.precioMayorista,
  });

  final int id;
  final int productoId;
  final String modelo;
  final String nombre;
  final Referencia? marca;
  final Referencia? linea;
  final Referencia? categoria;
  final String codigoCatalogo;
  final double precioMinoristaSugerido;
  final double? precioMayorista;

  final List<ImagenCatalogo> imagenes;
  final List<VarianteCatalogo> variantes;

  ImagenCatalogo? get imagenPrincipal {
    if (imagenes.isEmpty) return null;

    for (final imagen in imagenes) {
      if (imagen.esPrincipal) return imagen;
    }

    return ([...imagenes]..sort((a, b) => a.orden.compareTo(b.orden))).first;
  }

  List<ImagenCatalogo> get imagenesOrdenadas {
    return [...imagenes]..sort((a, b) {
      if (a.esPrincipal != b.esPrincipal) return a.esPrincipal ? -1 : 1;
      return a.orden.compareTo(b.orden);
    });
  }

  Disponibilidad? get mejorDisponibilidad {
    if (variantes.isEmpty) return null;

    return variantes
        .map((v) => v.disponibilidad)
        .reduce((mejor, otra) => otra.index < mejor.index ? otra : mejor);
  }

  Map<String, List<VarianteCatalogo>> get variantesPorColor {
    final grupos = <String, List<VarianteCatalogo>>{};

    for (final variante in variantes) {
      grupos.putIfAbsent(variante.colorParaMostrar, () => []).add(variante);
    }

    for (final tallas in grupos.values) {
      tallas.sort((a, b) => _compararTallas(a.talla, b.talla));
    }

    return grupos;
  }

  factory ProductoCatalogo.desdeJson(Map<String, dynamic> json) {
    final producto = json['producto'] as Map<String, dynamic>;

    return ProductoCatalogo(
      id: json['id'] as int,
      productoId: producto['id'] as int,
      modelo: producto['modelo'] as String,
      nombre: producto['nombre'] as String,
      marca: Referencia.desdeJson(producto['marca']),
      linea: Referencia.desdeJson(producto['linea']),
      categoria: Referencia.desdeJson(producto['categoria']),
      codigoCatalogo: json['codigo_catalogo'] as String,
      precioMinoristaSugerido: (json['precio_minorista_sugerido'] as num).toDouble(),
      precioMayorista: (json['precio_mayorista'] as num?)?.toDouble(),
      imagenes: (json['imagenes'] as List<dynamic>? ?? const [])
          .map((i) => ImagenCatalogo.desdeJson(i as Map<String, dynamic>))
          .toList(),
      variantes: (json['variantes'] as List<dynamic>? ?? const [])
          .map((v) => VarianteCatalogo.desdeJson(v as Map<String, dynamic>))
          .toList(),
    );
  }
}

/// Una línea con sus productos, para la primera pantalla del catálogo.
class LineaCatalogo {
  const LineaCatalogo({required this.nombre, required this.productos, this.id});

  final int? id;
  final String nombre;
  final List<ProductoCatalogo> productos;

  static const nombreSinLinea = 'Sin línea';

  static List<LineaCatalogo> agrupar(List<ProductoCatalogo> productos) {
    final porId = <int?, List<ProductoCatalogo>>{};
    final nombres = <int?, String>{};

    for (final producto in productos) {
      final id = producto.linea?.id;
      porId.putIfAbsent(id, () => []).add(producto);
      nombres[id] = producto.linea?.nombre ?? nombreSinLinea;
    }

    final lineas = porId.entries.map((grupo) {
      final ordenados = [...grupo.value]
        ..sort((a, b) => a.nombre.toLowerCase().compareTo(b.nombre.toLowerCase()));

      return LineaCatalogo(id: grupo.key, nombre: nombres[grupo.key]!, productos: ordenados);
    }).toList();

    lineas.sort((a, b) => (a.id == null ? 1 : (b.id == null ? -1 : a.nombre.toLowerCase().compareTo(b.nombre.toLowerCase()))));

    return lineas;
  }
}

int _compararTallas(String a, String b) {
  final numeroA = double.tryParse(a);
  final numeroB = double.tryParse(b);

  if (numeroA != null && numeroB != null) return numeroA.compareTo(numeroB);
  if (numeroA != null) return -1;
  if (numeroB != null) return 1;

  return a.compareTo(b);
}