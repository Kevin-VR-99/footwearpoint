<?php

use App\Models\Color;
use Illuminate\Database\Migrations\Migration;

/**
 * TG-282 — Más colores en la paleta, para que la IA tenga a dónde mandarlos.
 *
 * Al leer el Cklass completo quedaron 12 nombres comerciales sin resolver:
 * MALVA, MAQUILLAJE, MARINO, DORADO y compañía. No era culpa de la IA: la
 * instrucción le dice "elige uno exactamente de esta lista", y la lista eran
 * ocho colores sin rosa, sin morado, sin dorado y sin marino.
 *
 * Importa hacerlo ANTES de resolver esos nombres a mano. La decisión que se
 * toma una vez se recuerda para siempre (ColoresDelCatalogo::sugerir busca el
 * color que ya se usó para ese mismo nombre comercial), así que mandar MALVA
 * a Gris por no tener de otra se quedaría pegado en todos los catálogos que
 * vengan.
 *
 * Se usa firstOrCreate por nombre: si alguien ya lo creó a mano, no se pisa.
 */
return new class extends Migration
{
    /** @var array<int, array{nombre: string, codigo_hex: string}> */
    private const COLORES = [
        ['nombre' => 'Marino',     'codigo_hex' => '#1E293B'],
        ['nombre' => 'Rosa',       'codigo_hex' => '#EC4899'],
        ['nombre' => 'Morado',     'codigo_hex' => '#7E22CE'],
        ['nombre' => 'Dorado',     'codigo_hex' => '#C9A227'],
        ['nombre' => 'Plata',      'codigo_hex' => '#C0C0C0'],
        ['nombre' => 'Amarillo',   'codigo_hex' => '#EAB308'],
        ['nombre' => 'Naranja',    'codigo_hex' => '#EA580C'],
        ['nombre' => 'Vino',       'codigo_hex' => '#7F1D1D'],
        // Para las fotos de varios colores a la vez, que en calzado de dama
        // son bastantes ("MULTICOLOR", "GRIS/ROSA", "MARINO Y CAFÉ").
        ['nombre' => 'Multicolor', 'codigo_hex' => '#9CA3AF'],
    ];

    public function up(): void
    {
        foreach (self::COLORES as $color) {
            Color::firstOrCreate(
                ['nombre' => $color['nombre']],
                ['codigo_hex' => $color['codigo_hex'], 'activo' => true],
            );
        }
    }

    public function down(): void
    {
        // Solo los que nadie llegó a usar: una variante guarda su color_id y
        // borrarlo la dejaría sin color.
        foreach (self::COLORES as $color) {
            $registro = Color::where('nombre', $color['nombre'])->first();

            if ($registro && ! \App\Models\Variante::withoutGlobalScopes()->where('color_id', $registro->id)->exists()) {
                $registro->delete();
            }
        }
    }
};
