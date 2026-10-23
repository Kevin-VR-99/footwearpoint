# Guía de pruebas

## Reglas de pruebas

1. **Columnas JSON: nunca usar `assertSame` contra un arreglo leído de una columna JSON.** Usa `assertEquals` (o aplica `ksort` a ambos arreglos antes de comparar). Detalle abajo.

## Pruebas con columnas JSON: MariaDB vs MySQL

- **Local:** todos usamos XAMPP con **MariaDB 10.4**. Ahí `JSON` es un alias de `LONGTEXT`: se guarda como texto y **conserva el orden de claves** tal como se insertó.
- **Producción:** **MySQL 8/9**, con tipo `JSON` nativo. MySQL normaliza el objeto y **reordena las claves** (primero por longitud de la clave y luego por bytes).

Consecuencia: una prueba que pasa en local puede fallar en CI/producción si compara el orden de claves.

```php
// Mal: depende del orden de claves
$this->assertSame(['nombre' => 'Rojo', 'hex' => '#f00'], $modelo->colores);

// Bien: compara contenido sin importar el orden
$this->assertEquals(['nombre' => 'Rojo', 'hex' => '#f00'], $modelo->colores);

// También válido: ordenar antes
$esperado = ['nombre' => 'Rojo', 'hex' => '#f00'];
$real = $modelo->colores;
ksort($esperado); ksort($real);
$this->assertSame($esperado, $real);
```

Ejemplo real: PR #267 / #269, `AuditarColoresDelCatalogoTest`.